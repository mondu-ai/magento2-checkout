import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { sendWebhook, buildOrderConfirmedPayload } from '../helpers/webhook'
import { checkMonduOrderState, getMonduOrder } from '../helpers/api'
import { loginToAdmin, openOrderByMagentoId } from '../helpers/admin'

test('Webhook order/confirmed → Magento order moves from payment_review to processing', async ({
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

  // Wait until Mondu registers the order as pending (retries up to ~16s)
  await checkMonduOrderState(apiContext, orderUuid!, 'pending', 8, 2000)

  // Get external_reference_id (= Magento increment_id)
  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  const externalRefId: string = monduOrder.external_reference_id
  expect(externalRefId).toBeTruthy()

  // Verify order is in payment_review in Magento admin
  await loginToAdmin(page)
  await openOrderByMagentoId(page, externalRefId)
  const statusLocator = page
    .locator('tr:has(th:has-text("Order Status")) td, #order_status, .order-status')
    .first()
  await expect(statusLocator).toHaveText(/payment review/i)

  // Send order/confirmed webhook
  const payload = buildOrderConfirmedPayload(orderUuid!, externalRefId)
  const webhookResponse = await sendWebhook(apiContext, 'order/confirmed', payload)
  expect(webhookResponse.status()).toBe(200)

  // Poll until Magento order status changes to processing
  await expect(async () => {
    await page.reload()
    await page.waitForSelector('th:has-text("Order Status")', { timeout: 10_000 })
    await expect(
      page.locator('tr:has(th:has-text("Order Status")) td, #order_status, .order-status').first()
    ).toHaveText(/processing/i)
  }).toPass({ timeout: 30_000, intervals: [3_000] })

  await apiContext.dispose()
})