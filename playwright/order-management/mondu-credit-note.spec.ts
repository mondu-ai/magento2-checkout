import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { getMonduInvoices } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId, createInvoice, createShipment } from '../helpers/admin'

/**
 * Magento's Credit Memo button is driven by Order::canCreditmemo(), which comes down to whether
 * money was captured. A merchant who keeps the Magento invoice uncaptured until the money
 * actually arrives therefore never sees it and cannot credit anything, while Mondu holds the
 * invoice and will accept a credit note against it. Forcing Magento's screen open would leave
 * its books with more refunded than paid, so the module offers the Mondu side of the operation
 * on its own.
 */

test('Admin sends a credit note to Mondu from the order page', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  const orderUuid = await placeMonduOrder(page, 'mondu')
  expect(orderUuid).toBeTruthy()

  // The order grid is not reliably sorted newest first, so carry the number over from the
  // success page instead of trusting the first row.
  const orderNumber = (await page.locator('.checkout-success').innerText())
    .match(/\d{6,}/)?.[0]
  expect(orderNumber, 'could not read the order number off the success page').toBeTruthy()

  await loginToAdmin(page)
  await openOrderByMagentoId(page, orderNumber!)

  // "Require invoice for shipment" defaults to on, so the invoice has to exist before shipping,
  // and Mondu only holds an invoice to credit once the order has shipped.
  await createInvoice(page)
  await createShipment(page)

  // The local Mondu state catches up either through the webhook or the sync that follows
  // shipping, and the button waits for it.
  await expect(async () => {
    await page.reload()
    await expect(page.locator('#mondu_credit_note')).toBeVisible({ timeout: 5_000 })
  }).toPass({ timeout: 90_000 })

  await page.locator('#mondu_credit_note').click()
  await expect(page.locator('#mondu_invoice_uid')).toBeVisible()

  await page.fill('#mondu_amount', '1.00')
  await page.click('#mondu_send_credit_note')
  await expect(page.locator('.message-success')).toContainText('credit note was sent')

  const invoices = await getMonduInvoices(apiContext, orderUuid!)
  const creditNotes = invoices[0]?.credit_notes ?? []
  expect(creditNotes).toHaveLength(1)
  expect(creditNotes[0].gross_amount_cents).toBe(100)

  // What the operation did has to be visible where an admin looks, since it deliberately leaves
  // no Magento credit memo behind.
  await expect(page.getByText('Mondu: credit note', { exact: false }).first()).toBeVisible()

  await apiContext.dispose()
})
