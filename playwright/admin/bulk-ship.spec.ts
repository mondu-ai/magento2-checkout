import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { checkMonduOrderState } from '../helpers/api'
import {
  loginToAdmin,
  openFirstOrder,
  navigateToOrders,
  selectBulkAction,
} from '../helpers/admin'

test('Admin bulk ships multiple Mondu orders → invoices sent to Mondu for each', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()

  // Place two confirmed orders and capture their UUIDs and increment IDs
  const orderUuid1 = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid1).toBeTruthy()

  const orderUuid2 = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid2).toBeTruthy()

  // Get order increment IDs from admin
  await loginToAdmin(page)
  await navigateToOrders(page)

  // Grab the two most recent order increment IDs
  const incrementIds = await page
    .locator('.data-grid tbody tr td[data-column="increment_id"] a')
    .allTextContents()

  const [id1, id2] = incrementIds.slice(0, 2)

  // Perform bulk ship action
  await selectBulkAction(page, [id1, id2], 'Mondu Bulk Ship')

  await expect(page.locator('.message-success, .message-notice')).toBeVisible({ timeout: 60_000 })

  // Verify both orders are shipped in Mondu
  await checkMonduOrderState(apiContext, orderUuid1!, 'shipped', 10, 3000)
  await checkMonduOrderState(apiContext, orderUuid2!, 'shipped', 10, 3000)

  await apiContext.dispose()
})
