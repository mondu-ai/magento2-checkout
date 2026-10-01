import { test, expect, request as playwrightRequest, Page, APIRequestContext, Route } from '@playwright/test'
import {
  addProductToCart,
  proceedToCheckout,
  fillShippingAddress,
  selectPaymentMethod,
  placeOrder,
  payOnHostedCheckout,
  defaultCustomer,
} from '../helpers/checkout'
import { sendWebhook, buildOrderAuthorizedPayload } from '../helpers/webhook'
import { getMonduOrder } from '../helpers/api'
import { loginToAdmin, navigateToOrders } from '../helpers/admin'

const SUCCESS_PATH = '/mondu/payment_checkout/success'

// Calls the handler with the Mondu order uuid when the buyer's browser is about to return to the
// shop's success URL. Mondu reaches it through a 302, which page.route() does not see on its own,
// so navigations are fetched without following redirects. Returning false drops the navigation.
async function onSuccessRedirect(page: Page, handler: (orderUuid: string) => Promise<boolean>) {
  await page.route('**/*', async (route: Route) => {
    const request = route.request()
    if (!request.isNavigationRequest() || request.frame() !== page.mainFrame()) {
      return route.continue()
    }

    let target = request.url()
    let response = null
    if (!target.includes(SUCCESS_PATH)) {
      response = await route.fetch({ maxRedirects: 0 })
      target = response.headers()['location'] ?? ''
    }

    if (target.includes(SUCCESS_PATH)) {
      const orderUuid = new URL(target, request.url()).searchParams.get('order_uuid')
      if (orderUuid && !(await handler(orderUuid))) {
        return route.abort()
      }
    }

    return response ? route.fulfill({ response }) : route.continue()
  })
}

// Clicks through the hosted checkout until it pays: depending on the merchant it shows a payment
// terms step ("Weiter") before the pay button is enabled.
async function payOnHostedCheckoutSteps(page: Page): Promise<void> {
  await payOnHostedCheckout(page)
  const next = page.getByRole('button', { name: /^(weiter|continue|next)$/i }).first()
  const pay = page.getByRole('button', { name: /zahlen mit|pay with/i }).first()
  for (let step = 0; step < 10 && !page.isClosed() && page.url().includes('mondu.ai'); step++) {
    if (await next.waitFor({ state: 'visible', timeout: 2_000 }).then(() => true, () => false)) {
      await next.click()
    } else if (await pay.isEnabled().catch(() => false)) {
      await pay.click()
      return
    }
  }
}

async function startHostedCheckout(page: Page, email: string): Promise<void> {
  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, defaultCustomer(process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH', { email }))
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })
}

// Mondu sends the real order/authorized webhook to the shop as well, so the order may already be
// confirmed by the time the test's webhook arrives; either way it must end up confirmed exactly once.
async function waitForConfirmedWithIncrementId(api: APIRequestContext, orderUuid: string) {
  let order: any
  await expect(async () => {
    order = await getMonduOrder(api, orderUuid)
    expect(order.state).toBe('confirmed')
    expect(order.external_reference_id).toMatch(/^\d+$/)
  }).toPass({ timeout: 60_000, intervals: [2_000] })
  return order
}

async function countOrdersForEmail(page: Page, email: string): Promise<number> {
  await navigateToOrders(page)
  const search = page.locator('#fulltext').first()
  await search.fill(email)
  await search.press('Enter')
  // The grid shows the active keyword once the filtered rows are loaded
  await page.locator('.admin__current-filters-list').getByText(email).first().waitFor({ timeout: 30_000 })
  await page.waitForLoadState('networkidle')
  return page.locator('.data-grid tbody tr.data-row').count()
}

test('Buyer closes the tab before the redirect: order/authorized webhook places and confirms the order', async ({
  page,
}) => {
  const api = await playwrightRequest.newContext()
  const email = `ac.good.closed.${Date.now()}@example.com`

  await startHostedCheckout(page, email)

  // The buyer pays, but the browser never reaches the shop's success URL.
  let orderUuid: string | null = null
  await onSuccessRedirect(page, async (uuid) => {
    orderUuid = uuid
    return false
  })
  await payOnHostedCheckoutSteps(page)
  await expect.poll(() => orderUuid, { timeout: 90_000 }).toBeTruthy()
  await page.close()

  const monduOrder = await getMonduOrder(api, orderUuid!)
  expect(['authorized', 'confirmed']).toContain(monduOrder.state)

  const response = await sendWebhook(
    api,
    'order/authorized',
    buildOrderAuthorizedPayload(orderUuid!, monduOrder.external_reference_id)
  )
  expect(response.status()).toBe(200)

  const confirmed = await waitForConfirmedWithIncrementId(api, orderUuid!)

  const adminPage = await page.context().newPage()
  await loginToAdmin(adminPage)
  expect(await countOrdersForEmail(adminPage, email)).toBe(1)
  // The grid is still filtered to this buyer's single order
  const row = adminPage.locator('.data-grid tbody tr.data-row').first()
  await expect(row).toContainText(confirmed.external_reference_id)
  await row.locator('a[href*="sales/order/view"]').click()
  await adminPage.waitForSelector('.page-title', { timeout: 20_000 })
  await expect(adminPage.locator('#order_history_block, .order-history-block').first()).toContainText(
    'did not return from the Mondu checkout'
  )

  await api.dispose()
})

test('Buyer returns while the webhook places the order: exactly one Magento order', async ({ page }) => {
  const api = await playwrightRequest.newContext()
  const email = `ac.good.race.${Date.now()}@example.com`

  await startHostedCheckout(page, email)

  // Fire the webhook at the moment the buyer's browser comes back to the shop.
  let orderUuid: string | null = null
  let webhook: Promise<number> | null = null
  await onSuccessRedirect(page, async (uuid) => {
    orderUuid = uuid
    webhook = getMonduOrder(api, uuid).then((order) =>
      sendWebhook(api, 'order/authorized', buildOrderAuthorizedPayload(uuid, order.external_reference_id))
        .then((r) => r.status())
    )
    return true
  })
  await payOnHostedCheckoutSteps(page)

  await page.waitForURL('**/checkout/onepage/success/**', { timeout: 90_000 })
  expect(await webhook).toBe(200)

  await waitForConfirmedWithIncrementId(api, orderUuid!)

  await loginToAdmin(page)
  expect(await countOrdersForEmail(page, email)).toBe(1)

  await api.dispose()
})
