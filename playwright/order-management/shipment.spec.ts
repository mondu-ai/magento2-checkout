import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { checkMonduOrderState } from '../helpers/api'
import { loginToAdmin, openFirstOrder, createShipment } from '../helpers/admin'

test('Admin creates shipment → invoice sent to Mondu, order state becomes shipped', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()

  // Place a confirmed order
  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  // Go to admin and create shipment
  await loginToAdmin(page)
  await openFirstOrder(page)
  await createShipment(page)

  // Verify admin success message
  await expect(page.locator('.message-success')).toBeVisible()

  // Verify Mondu API state updated
  await checkMonduOrderState(apiContext, orderUuid!, 'shipped', 8, 3000)

  // Verify order comment mentions shipment
  const comments = page.locator('.order-history-block, .order-comments-history')
  await expect(comments).toBeVisible()

  await apiContext.dispose()
})
