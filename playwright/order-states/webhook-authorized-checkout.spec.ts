import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { sendWebhook, buildOrderAuthorizedPayload } from '../helpers/webhook'
import { checkMonduOrderState, getMonduOrder } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId } from '../helpers/admin'

/**
 * order/authorized is meant for orders placed from the admin, where the buyer still has to
 * confirm. Mondu sends it for checkout orders too, and it can arrive after the order was
 * confirmed. A checkout order must stay in processing.
 */
test('Webhook order/authorized → a confirmed checkout order stays processing', async ({ page }) => {
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
  const externalRefId: string = (await getMonduOrder(apiContext, orderUuid!)).external_reference_id

  await loginToAdmin(page)
  await openOrderByMagentoId(page, externalRefId)
  const status = page.locator('tr:has(th:has-text("Order Status")) td, #order_status, .order-status').first()
  await expect(status).toHaveText(/processing/i)

  const response = await sendWebhook(
    apiContext,
    'order/authorized',
    buildOrderAuthorizedPayload(orderUuid!, externalRefId)
  )
  expect(response.status()).toBe(200)

  await page.reload()
  await expect(status).toHaveText(/processing/i)
  await expect(page.locator('#order_history_block')).not.toContainText('Waiting for buyer to confirm')

  await apiContext.dispose()
})
