import { test, expect, request as playwrightRequest, Page } from '@playwright/test'
import {
  addProductToCart,
  fillShippingAddress,
  handleMonduCheckout,
  placeOrder,
  proceedToCheckout,
  selectPaymentMethod,
} from '../helpers/checkout'
import { getMonduOrder } from '../helpers/api'

/**
 * Net term selection in the storefront checkout.
 *
 * Requires the shop to have more than one net term enabled under
 * Stores > Configuration > Payment Methods > Mondu > Net terms offered to buyers,
 * and the terms have to be ones the Mondu account holds for the buyer country.
 * The spec reads the offered terms from the page rather than hardcoding them, so it
 * follows whatever the shop is configured with, and only needs at least two.
 */

const NET_TERM_SELECT = '.payment-method._active .mondu-net-term select'

async function reachPaymentStep(page: Page): Promise<void> {
  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, {
    firstName: 'Jane',
    lastName: 'Doe',
    email: `ac.good.${Date.now()}@example.com`,
    company: process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
    street: 'Strassmannstr. 45',
    zip: '10122',
    city: 'Berlin',
    country: 'DE',
    phone: '+493031196513',
  })
  await selectPaymentMethod(page, 'mondu')
}

test('Checkout offers the enabled net terms and preselects 30 days', async ({ page }) => {
  await reachPaymentStep(page)

  const select = page.locator(NET_TERM_SELECT)
  await select.waitFor({ state: 'visible', timeout: 15_000 })

  const offered = await select.locator('option').evaluateAll((options) =>
    options.map((option) => (option as HTMLOptionElement).value)
  )

  expect(
    offered.length,
    'the selector only appears with more than one enabled term, so the shop needs at least two configured'
  ).toBeGreaterThan(1)

  // What the checkout offers has to be what the account holds for this payment
  // method and this country, the two dimensions order creation validates, or it
  // answers 422 "proposed net terms is not available for merchant".
  const config = await page.evaluate(() => (window as any).checkoutConfig.monduNetTerms)
  const allowed = config.byMethod.mondu.DE
  expect(offered.map(Number).sort((a, b) => a - b)).toEqual(
    config.available.filter((term: number) => allowed.includes(term))
  )

  // 30 days is the house default whenever the merchant offers it.
  if (offered.includes('30')) {
    await expect(select).toHaveValue('30')
  }
})

test('The picked net term is the one Mondu authorizes', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  await reachPaymentStep(page)

  const select = page.locator(NET_TERM_SELECT)
  await select.waitFor({ state: 'visible', timeout: 15_000 })

  // Deliberately not the preselected one: a term that survives to the API only
  // proves anything if it differs from the default the account would apply anyway.
  const offered = await select.locator('option').evaluateAll((options) =>
    options.map((option) => (option as HTMLOptionElement).value)
  )
  const preselected = await select.inputValue()
  const picked = offered.find((term) => term !== preselected)

  expect(picked, 'need a second term to tell the pick apart from the default').toBeTruthy()
  await select.selectOption(picked!)

  await placeOrder(page)
  const orderUuid = await handleMonduCheckout(page)

  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  expect(monduOrder.authorized_net_term).toBe(Number(picked))

  await apiContext.dispose()
})
