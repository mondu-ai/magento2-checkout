import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder, getOrderIncrementId } from '../helpers/checkout'
import { checkMonduOrderState } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId, createInvoice, createCreditMemo } from '../helpers/admin'

test('Admin creates full credit memo on unshipped order → Cancel API called in Mondu', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()

  // Place a confirmed order (do NOT ship it)
  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()
  // Open exactly this order in admin later — the grid's first row is not a reliable anchor
  const incrementId = await getOrderIncrementId(page)

  await loginToAdmin(page)
  await openOrderByMagentoId(page, incrementId)
  // A credit memo requires a Magento invoice; the order stays unshipped, so Mondu still cancels
  await createInvoice(page)
  await openOrderByMagentoId(page, incrementId)

  // Create full credit memo without an invoice UUID (triggers cancel path)
  await createCreditMemo(page)

  await expect(page.locator('.message-success')).toBeVisible()

  // Full refund on unshipped order should cancel in Mondu
  await checkMonduOrderState(apiContext, orderUuid!, 'canceled', 8, 3000)

  await apiContext.dispose()
})
