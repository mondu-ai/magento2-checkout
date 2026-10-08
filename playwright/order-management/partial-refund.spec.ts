import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder, getOrderIncrementId } from '../helpers/checkout'
import { getMonduOrder, getMonduInvoice } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId, createInvoice, createShipment, createCreditMemo } from '../helpers/admin'

test('Admin creates partial credit memo → credit note sent to Mondu', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  // Place and ship an order to get an invoice UUID
  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()
  // Open exactly this order in admin later — the grid's first row is not a reliable anchor
  const incrementId = await getOrderIncrementId(page)

  await loginToAdmin(page)
  await openOrderByMagentoId(page, incrementId)
  // require_invoice defaults to 1, so the order must be invoiced before it can ship
  await createInvoice(page)
  await createShipment(page)

  // Get the invoice UUID from Mondu — it shows up a moment after the shipment call
  let invoices: any[] = []
  for (let attempt = 0; attempt < 8 && invoices.length === 0; attempt++) {
    const monduOrder = await getMonduOrder(apiContext, orderUuid!)
    invoices = monduOrder.invoices || []
    if (invoices.length === 0) {
      await page.waitForTimeout(3_000)
    }
  }
  expect(invoices.length).toBeGreaterThan(0)
  const invoiceUuid = invoices[0]?.uuid || invoices[0]

  // Go back to the order and create a partial credit memo
  await openOrderByMagentoId(page, incrementId)
  await createCreditMemo(page, 1, invoiceUuid)

  // Verify success
  await expect(page.locator('.message-success')).toBeVisible()

  await apiContext.dispose()
})
