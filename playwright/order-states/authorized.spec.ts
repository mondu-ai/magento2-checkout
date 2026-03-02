import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { checkMonduOrderState, getMonduOrder } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId } from '../helpers/admin'

test('Order placed with auto-authorized buyer → Magento order state is processing', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()
  const email = `ac.good.${Date.now()}@example.com`

  const orderUuid = await placeMonduOrder(
    page,
    'mondu',
    process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
    { email }
  )

  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  await checkMonduOrderState(apiContext, orderUuid!, 'confirmed', 5, 2000)

  // Get Magento increment_id from Mondu API
  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  const externalRefId: string = monduOrder.external_reference_id

  await loginToAdmin(page)
  await openOrderByMagentoId(page, externalRefId)

  const statusBadge = page
    .locator('tr:has(th:has-text("Order Status")) td, #order_status, .order-status')
    .first()
  await expect(statusBadge).not.toHaveText(/payment review/i)
  await expect(statusBadge).toHaveText(/processing/i)

  await apiContext.dispose()
})
