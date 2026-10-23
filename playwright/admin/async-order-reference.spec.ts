import { test, expect, request as playwrightRequest } from '@playwright/test'
import { createAdminOrder } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'

/**
 * Reference of an order placed from the admin (async flow).
 *
 * create_async answers a reference Mondu already holds a confirmed order under with that
 * order instead of creating one, and increment ids repeat across websites sharing a Mondu
 * account and on shared sandbox accounts. The order therefore goes to Mondu under a
 * reference of its own, and the increment id follows once the buyer confirmed.
 *
 * The buyer confirms on the Mondu page the confirmation email links to (confirmation_link),
 * so order/confirmed is Mondu's own webhook. Requires admin order creation to be switched
 * on, a Mondu account that holds an async net term for DE, and webhooks reaching the shop.
 */

test('An admin order goes to Mondu under its own reference and gets the order number once confirmed', async ({ page }) => {
  test.setTimeout(300_000)
  const apiContext = await playwrightRequest.newContext()

  await createAdminOrder(page, `ac.good.${Date.now()}@example.com`)

  const comment = await page.locator('#order_history_block').innerText()
  const orderUuid = comment.match(/uuid ([0-9a-f-]{36})/)?.[1]
  expect(orderUuid, 'the async order comment carries the Mondu uuid').toBeTruthy()
  const incrementId = (await page.locator('h1.page-title').innerText()).replace('#', '').trim()
  expect(incrementId).toMatch(/^\d+$/)

  // A new order, created just now under the module's own reference.
  const created = await getMonduOrder(apiContext, orderUuid!)
  expect(created.external_reference_id).toMatch(/^M2_/)
  expect(created.external_reference_id).not.toBe(incrementId)
  expect(Date.now() - Date.parse(created.created_at)).toBeLessThan(10 * 60_000)

  let confirmationLink = ''
  await expect
    .poll(
      async () => {
        const order = await getMonduOrder(apiContext, orderUuid!)
        confirmationLink = order.confirmation_link ?? ''
        return order.state
      },
      { timeout: 90_000, intervals: [3_000] }
    )
    .toBe('authorized')
  expect(confirmationLink, 'Mondu offers the buyer a confirmation page').toBeTruthy()

  // The buyer confirms the order details on the Mondu page.
  const portal = await page.context().newPage()
  const confirmButton = portal.getByRole('button', { name: /Bestelldetails bestätigen|Confirm order details/ })
  await expect(async () => {
    await portal.goto(confirmationLink)
    await expect(confirmButton).toBeVisible({ timeout: 20_000 })
  }).toPass({ timeout: 90_000 })
  const confirmed = portal.waitForResponse(
    (response) => response.url().includes('/buyers/orders/confirm') && response.request().method() === 'POST'
  )
  await confirmButton.click()
  expect((await confirmed).status()).toBe(200)
  await portal.close()

  // order/confirmed: the module sends the increment id with update_external_info.
  await expect
    .poll(async () => (await getMonduOrder(apiContext, orderUuid!)).external_reference_id, {
      timeout: 90_000,
      intervals: [3_000],
    })
    .toBe(incrementId)
  expect((await getMonduOrder(apiContext, orderUuid!)).state).toBe('confirmed')

  await expect
    .poll(
      async () => {
        await page.reload()
        return (await page.locator('#order_status').innerText()).trim()
      },
      { timeout: 60_000, intervals: [5_000] }
    )
    .toBe('Processing')

  await apiContext.dispose()
})
