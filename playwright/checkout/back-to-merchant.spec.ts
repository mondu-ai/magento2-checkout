import { test, expect, request as playwrightRequest } from '@playwright/test'
import {
  addProductToCart,
  proceedToCheckout,
  fillShippingAddress,
  selectPaymentMethod,
  placeOrder,
  continueToPayment,
} from '../helpers/checkout'
import { getLastMonduOrder } from '../helpers/api'

const MAGENTO = (process.env.MAGENTO_URL || '').replace(/\/$/, '')

const buyer = () => ({
  firstName: process.env.BUYER_FIRST_NAME || 'Jane',
  lastName: process.env.BUYER_LAST_NAME || 'Doe',
  email: `ac.good.${Date.now()}@example.com`,
  company: process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
  street: process.env.BUYER_STREET || 'Strassmannstr. 45',
  zip: process.env.BUYER_ZIP || '10122',
  city: process.env.BUYER_CITY || 'Berlin',
  country: process.env.BUYER_COUNTRY || 'DE',
  phone: process.env.BUYER_PHONE || '+493031196513',
})

/**
 * "Zurück zum Händler" on the hosted checkout page hits cancel_url. That used to land the buyer
 * on the cart page with checkout-data already invalidated, so the cart's shipping estimator had
 * no address to work from and saved the store default country with an empty postcode onto the
 * quote. The buyer then could not place the order at all - Mondu rejected the payload as missing
 * shipping_address.zip_code - while the checkout form still looked filled in.
 *
 * The assertion is on the address that reaches Mondu on the second attempt, not on the checkout
 * form: the form looking right while the quote behind it is empty is the bug, so reading the
 * inputs would reproduce the same blind spot.
 */
test('Coming back from Mondu keeps the address the buyer entered', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()
  const customer = buyer()

  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, customer)
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })

  // What the "back to merchant" link does.
  await page.goto(`${MAGENTO}/mondu/payment_checkout/cancel`)
  await page.waitForLoadState('networkidle')

  // The buyer belongs on checkout, not on the cart page whose estimator wipes the address.
  expect(page.url()).toContain('/checkout')
  expect(page.url()).not.toContain('/checkout/cart')

  // Second attempt has to get through, which it cannot do on a wiped quote: Magento itself
  // refuses to leave the shipping step without a postcode.
  await continueToPayment(page)
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })

  const monduOrder = await getLastMonduOrder(apiContext)
  expect(monduOrder.shipping_address.zip_code).toBe(customer.zip)
  expect(monduOrder.shipping_address.city).toBe(customer.city)
  expect(monduOrder.shipping_address.country_code).toBe(customer.country)
  expect(monduOrder.billing_address.zip_code).toBe(customer.zip)

  await apiContext.dispose()
})

/**
 * The cart page runs a shipping estimator. It reuses the address kept in the checkout-data
 * section, and when that section has been dropped it falls back to the store default country
 * with no postcode - and saves that onto the quote. Starting a Mondu payment used to drop the
 * section, so any later visit to the cart, not just the one cancel_url forced, wiped the address.
 */
test('Visiting the cart after starting a Mondu payment keeps the address', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()
  const customer = buyer()

  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, customer)
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })

  await page.goto(`${MAGENTO}/checkout/cart/`)
  await page.waitForLoadState('networkidle')
  // Give the estimator time to fire and persist whatever it decided on.
  await page.waitForTimeout(6_000)

  await page.goto(`${MAGENTO}/checkout/`)
  await continueToPayment(page)
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })

  const monduOrder = await getLastMonduOrder(apiContext)
  expect(monduOrder.shipping_address.zip_code).toBe(customer.zip)
  expect(monduOrder.shipping_address.country_code).toBe(customer.country)

  await apiContext.dispose()
})
