import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder, getOrderIncrementId } from '../helpers/checkout'
import { checkMonduOrderState } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId, cancelOrder } from '../helpers/admin'

test('Admin cancels order → Cancel API called, Mondu order state is canceled', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()

  // Place a confirmed order
  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()
  // Open exactly this order in admin later — the grid's first row is not a reliable anchor
  const incrementId = await getOrderIncrementId(page)

  await loginToAdmin(page)
  await openOrderByMagentoId(page, incrementId)
  await cancelOrder(page)

  // Verify Magento order is canceled
  await expect(page.locator('#order_status, .order-status').first()).toHaveText(/cancel/i)

  // Verify Mondu API state
  await checkMonduOrderState(apiContext, orderUuid!, 'canceled', 8, 3000)

  // Verify order comment
  const comments = page.locator('.order-history-block, .order-comments-history').first()
  await expect(comments).toContainText(/cancel/i)

  await apiContext.dispose()
})
