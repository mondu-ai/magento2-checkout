import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { getMonduOrder, getMonduInvoice } from '../helpers/api'
import { loginToAdmin, openFirstOrder, createShipment, createCreditMemo } from '../helpers/admin'

test('Admin creates partial credit memo → credit note sent to Mondu', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  // Place and ship an order to get an invoice UUID
  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  await loginToAdmin(page)
  await openFirstOrder(page)
  await createShipment(page)

  // Get the invoice UUID from Mondu
  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  const invoices = monduOrder.invoices || []
  expect(invoices.length).toBeGreaterThan(0)
  const invoiceUuid = invoices[0]?.uuid || invoices[0]

  // Go back to the order and create a partial credit memo
  await openFirstOrder(page)
  await createCreditMemo(page, 1, invoiceUuid)

  // Verify success
  await expect(page.locator('.message-success')).toBeVisible()

  await apiContext.dispose()
})
