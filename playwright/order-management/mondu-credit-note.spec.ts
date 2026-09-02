import { test, expect } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { loginToAdmin, openOrderByMagentoId, createInvoice, createShipment } from '../helpers/admin'

/**
 * "Send credit note to Mondu" is a fallback for orders Magento cannot credit at all, not a second
 * way of refunding. Magento decides from the money it believes was collected, so an invoice that
 * was never captured leaves total_paid empty and the Credit Memo button hidden, while Mondu holds
 * the invoice and will accept a credit note against it.
 *
 * Everywhere else the standard credit memo is the process to use, because it books the refund on
 * both sides. This covers that boundary on a normally invoiced order: Magento offers its own
 * button and ours stays away. The controller applies the same rule again for anyone arriving by
 * URL, which is not asserted here because admin URLs carry a secret key and Magento turns such a
 * request away before the controller runs.
 *
 * The other side of the boundary needs an uncaptured invoice, which neither the admin nor the
 * REST API will produce (both capture offline), so it is exercised by hand rather than here.
 */
test('The Mondu credit note stays out of the way of the standard credit memo', async ({ page }) => {
  const orderUuid = await placeMonduOrder(page, 'mondu')
  expect(orderUuid).toBeTruthy()

  const orderNumber = (await page.locator('.checkout-success').innerText()).match(/\d{6,}/)?.[0]
  expect(orderNumber, 'could not read the order number off the success page').toBeTruthy()

  await loginToAdmin(page)
  await openOrderByMagentoId(page, orderNumber!)

  // A normally invoiced order: Magento captures offline, so it can credit the order itself.
  await createInvoice(page)
  await createShipment(page)

  await expect(page.locator('#order_creditmemo')).toBeVisible()
  await expect(page.locator('#mondu_credit_note')).toHaveCount(0)
})
