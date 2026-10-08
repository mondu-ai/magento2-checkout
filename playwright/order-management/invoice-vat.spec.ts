import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { getMonduOrder, waitForMonduInvoice } from '../helpers/api'
import {
  loginToAdmin,
  openOrderByMagentoId,
  createInvoice,
  createShipment,
} from '../helpers/admin'

/**
 * tax_cents and shipping_price_cents are optional on the invoice endpoint and Mondu does not
 * copy them over from the order, because an invoice may cover only part of a shipment. An
 * invoice sent without them is stored with tax_cents = null, which is what makes the merchant
 * portal render VAT as 0,00 €. This asserts the module now sends the VAT it already knows.
 *
 * The shop must charge VAT for the buyer country, otherwise the run proves nothing and the
 * test says so instead of passing silently.
 */
test('Invoice sent to Mondu carries the VAT and shipping amounts', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  const orderTaxCents = monduOrder.lines?.[0]?.tax_cents ?? 0
  const orderShippingCents = monduOrder.lines?.[0]?.shipping_price_cents ?? 0
  expect(orderTaxCents, 'the shop must charge VAT, otherwise this run proves nothing')
    .toBeGreaterThan(0)

  await loginToAdmin(page)
  await openOrderByMagentoId(page, monduOrder.external_reference_id)
  // "Require invoice for shipment" defaults to on, so the invoice document has to exist first.
  await createInvoice(page)
  await createShipment(page)

  const invoice = await waitForMonduInvoice(apiContext, orderUuid!)

  expect(invoice.tax_cents).toBe(orderTaxCents)
  expect(invoice.shipping_price_cents).toBe(orderShippingCents)

  await apiContext.dispose()
})
