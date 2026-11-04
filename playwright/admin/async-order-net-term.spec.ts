import { test, expect, request as playwrightRequest } from '@playwright/test'
import { createAdminOrder } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'

/**
 * Net term of an order placed from the admin (async flow).
 *
 * The order is created with create_async, whose answer comes before Mondu has
 * decided and carries no authorized term. Once Mondu sends order/authorized the
 * module reads the term from the API, so the merchant finds the term to invoice
 * on in the order view and in the Mondu orders grid.
 *
 * Requires admin order creation to be switched on and a Mondu account that
 * holds an async net term for DE. The webhook must reach the shop.
 */

test('An admin order shows the term Mondu authorized once order/authorized arrives', async ({ page }) => {
  test.setTimeout(240_000)
  const apiContext = await playwrightRequest.newContext()

  const { netTerm } = await createAdminOrder(page, `ac.good.${Date.now()}@example.com`)
  expect(netTerm, 'the account must hold an async term for DE').toBeTruthy()

  // The order view, right after the order was created.
  const comment = await page.locator('#order_history_block').innerText()
  const orderUuid = comment.match(/uuid ([0-9a-f-]{36})/)?.[1]
  expect(orderUuid, 'the async order comment carries the Mondu uuid').toBeTruthy()
  const orderUrl = page.url()

  // Mondu decides on its own schedule; the grid shows the authorized term only,
  // so it fills in once the webhook stored it.
  let monduOrder: any = null
  await expect
    .poll(
      async () => {
        monduOrder = await getMonduOrder(apiContext, orderUuid!)
        return monduOrder.authorized_net_term ?? null
      },
      { timeout: 90_000, intervals: [3_000] }
    )
    .not.toBeNull()

  await page.locator('#menu-mondu-mondu-log > a').click()
  await page.waitForURL(/mondu\/log/, { timeout: 30_000 })
  const row = page.locator('.data-grid tbody tr', { hasText: orderUuid! })
  await row.waitFor({ state: 'visible', timeout: 60_000 })
  const headers = await page.locator('.data-grid thead th').allInnerTexts()
  const termColumn = headers.findIndex((header) => header.trim() === 'Payment term')
  expect(termColumn, 'the grid has a Payment term column').toBeGreaterThanOrEqual(0)

  await expect
    .poll(
      async () => {
        await page.reload()
        return (await row.locator('td').nth(termColumn).innerText()).trim()
      },
      { timeout: 90_000, intervals: [5_000], message: 'order/authorized must store the authorized term' }
    )
    .toBe(String(monduOrder.authorized_net_term))

  await page.goto(orderUrl)
  // The admin shows the payment details as plain lines below the method title.
  await expect(page.locator('.order-payment-method')).toContainText(
    `Payment term: ${monduOrder.authorized_net_term} days`
  )

  await apiContext.dispose()
})
