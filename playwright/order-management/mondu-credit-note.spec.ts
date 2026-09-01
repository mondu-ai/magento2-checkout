import { test, expect, request as playwrightRequest, Page } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { loginToAdmin, openOrderByMagentoId } from '../helpers/admin'

/**
 * Magento's Credit Memo button is driven by Order::canCreditmemo(), which comes down to whether
 * money was captured. A merchant who keeps the Magento invoice uncaptured until the money
 * actually arrives therefore never sees it and cannot credit anything, while Mondu holds the
 * invoice and will accept a credit note against it. Forcing Magento's screen open would leave
 * its books with more refunded than paid, so the module offers the Mondu side of the operation
 * on its own.
 *
 * The steps below drive the admin through element ids rather than the shared helpers: Magento
 * 2.4.9 prefixes data-ui-id with the button-list name, which the helpers on this branch do not
 * account for yet.
 */
const SUBMIT_ORDER_DOCUMENT = '[data-ui-id="order-items-submit-button"]'

// Read straight from the API rather than through helpers/api.ts: the order response does not
// carry invoices, and keeping the call here leaves the shared helper file untouched.
async function monduCreditNotes(
  apiContext: import('@playwright/test').APIRequestContext,
  orderUuid: string
) {
  const response = await apiContext.get(
    `${process.env.API_URL || 'https://api.demo.mondu.ai/api/v1'}/orders/${orderUuid}/invoices`,
    { headers: { 'Api-Token': process.env.API_TOKEN || '', 'Content-Type': 'application/json' } }
  )
  if (!response.ok()) {
    throw new Error(`Failed to list invoices for order ${orderUuid}: ${response.status()}`)
  }
  const invoices = (await response.json()).invoices ?? []

  return invoices[0]?.credit_notes ?? []
}

async function submitDocument(page: Page, openButtonId: string): Promise<void> {
  await page.locator(openButtonId).first().click()
  await page.waitForSelector(SUBMIT_ORDER_DOCUMENT, { timeout: 30_000 })
  await page.locator(SUBMIT_ORDER_DOCUMENT).click()
  await page.waitForSelector('.message-success', { timeout: 40_000 })
}

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
  await submitDocument(page, '#order_invoice')
  await submitDocument(page, '#order_ship')

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

  const creditNotes = await monduCreditNotes(apiContext, orderUuid!)
  expect(creditNotes).toHaveLength(1)
  expect(creditNotes[0].gross_amount_cents).toBe(100)

  // What the operation did has to be visible where an admin looks, since it deliberately leaves
  // no Magento credit memo behind.
  await expect(page.getByText('Mondu: credit note', { exact: false }).first()).toBeVisible()

  await apiContext.dispose()
})
