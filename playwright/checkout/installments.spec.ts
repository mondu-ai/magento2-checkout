import { test, expect } from '@playwright/test'
import {
  addProductToCart,
  proceedToCheckout,
  fillShippingAddress,
  selectPaymentMethod,
  placeOrder,
} from '../helpers/checkout'

test('Place order with Installments (monduinstallment) payment method', async ({ page }) => {
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
  await selectPaymentMethod(page, 'monduinstallment')

  // Capture the token request URL to verify the correct payment_method is sent
  let capturedTokenUrl = ''
  page.on('request', (req) => {
    if (req.url().includes('/payment_checkout/token')) {
      capturedTokenUrl = req.url()
    }
  })

  await placeOrder(page)

  // Wait for redirect to Mondu hosted checkout — confirms token was created
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })

  // Verify the correct payment_method was sent to Mondu
  expect(capturedTokenUrl).toContain('payment_method=installment')
})
