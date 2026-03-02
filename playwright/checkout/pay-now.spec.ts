import { test, expect, request as playwrightRequest } from '@playwright/test'
import {
  addProductToCart,
  proceedToCheckout,
  fillShippingAddress,
  selectPaymentMethod,
  placeOrder,
} from '../helpers/checkout'
import { getLastMonduOrder } from '../helpers/api'

test('Place order with Pay Now (mondupaynow) payment method', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, {
    firstName: process.env.BUYER_FIRST_NAME || 'Jane',
    lastName: process.env.BUYER_LAST_NAME || 'Doe',
    email: process.env.BUYER_EMAIL || 'accepted.test@example.com',
    company: process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
    street: process.env.BUYER_STREET || 'Strassmannstr. 45',
    zip: process.env.BUYER_ZIP || '10122',
    city: process.env.BUYER_CITY || 'Berlin',
    country: process.env.BUYER_COUNTRY || 'DE',
    phone: process.env.BUYER_PHONE || '+493031196513',
  })
  await selectPaymentMethod(page, 'mondupaynow')

  // Capture token response body before page navigates away
  let tokenError = false
  await page.route('**/mondu/payment_checkout/token', async (route) => {
    const response = await route.fetch()
    try {
      const body = await response.json()
      tokenError = !!body.error
    } catch {
      tokenError = true
    }
    await route.fulfill({ response })
  })

  await placeOrder(page)

  // Wait for redirect to Mondu hosted checkout — confirms token was created
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })
  await page.unroute('**/mondu/payment_checkout/token')

  expect(tokenError).toBe(false)

  // Verify the Mondu order was created with the correct payment method
  const lastOrder = await getLastMonduOrder(apiContext)
  expect(lastOrder).toBeTruthy()
  expect(lastOrder.payment_method).toMatch(/pay_now/i)

  await apiContext.dispose()
})
