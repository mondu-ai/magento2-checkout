import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { getMonduOrder } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId } from '../helpers/admin'

test('Order placed with pending buyer → Magento order state is payment_review', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()
  const email = `pending.good.${Date.now()}@example.com`

  const orderUuid = await placeMonduOrder(
    page,
    'mondu',
    process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
    { email }
  )

  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  expect(monduOrder.state).toBe('pending')
  const externalRefId: string = monduOrder.external_reference_id

  await loginToAdmin(page)
  await openOrderByMagentoId(page, externalRefId)

  const statusBadge = page
    .locator('tr:has(th:has-text("Order Status")) td, #order_status, .order-status')
    .first()
  await expect(statusBadge).toHaveText(/payment review/i)

  const comments = page.locator('.order-history-block').first()
  await expect(comments).toContainText(/payment review|pending/i)

  await apiContext.dispose()
})
