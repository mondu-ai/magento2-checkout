import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { checkMonduOrderState } from '../helpers/api'
import { loginToAdmin, openFirstOrder, createCreditMemo } from '../helpers/admin'

test('Admin creates full credit memo on unshipped order → Cancel API called in Mondu', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()

  // Place a confirmed order (do NOT ship it)
  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  await loginToAdmin(page)
  await openFirstOrder(page)

  // Create full credit memo without an invoice UUID (triggers cancel path)
  await createCreditMemo(page)

  await expect(page.locator('.message-success')).toBeVisible()

  // Full refund on unshipped order should cancel in Mondu
  await checkMonduOrderState(apiContext, orderUuid!, 'canceled', 8, 3000)

  await apiContext.dispose()
})
