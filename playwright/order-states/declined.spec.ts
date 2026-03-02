import { test, expect } from '@playwright/test'
import {
  addProductToCart,
  proceedToCheckout,
  fillShippingAddress,
  selectPaymentMethod,
  placeOrder,
} from '../helpers/checkout'

test('Payment declined → customer redirected with error message', async ({ page }) => {
  const company = process.env.BUYER_COMPANY_DECLINED || 'Test Declined Company GmbH'

  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, {
    firstName: process.env.BUYER_FIRST_NAME || 'Jane',
    lastName: process.env.BUYER_LAST_NAME || 'Doe',
    email: process.env.BUYER_EMAIL_DECLINED || 'declined@example.com',
    company,
    street: process.env.BUYER_STREET || 'Strassmannstr. 45',
    zip: process.env.BUYER_ZIP || '10122',
    city: process.env.BUYER_CITY || 'Berlin',
    country: process.env.BUYER_COUNTRY || 'DE',
    phone: process.env.BUYER_PHONE || '+493031196513',
  })
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)

  // Wait for Mondu hosted checkout (order was created on Mondu side)
  await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })

  // Simulate decline by navigating directly to the Magento decline controller
  // (Tests that the controller correctly sets the error message and redirects to cart)
  const magentoUrl = process.env.MAGENTO_URL?.replace(/\/$/, '') || ''
  await page.goto(`${magentoUrl}/mondu/payment_checkout/decline`)

  // Decline controller redirects to checkout/cart with error message
  await page.waitForURL(/checkout\/cart/, { timeout: 15_000 })

  const errorMessage = page.locator('.message-error, .messages .message, .error-message')
  await expect(errorMessage).toBeVisible({ timeout: 15_000 })
  // Message starts with "Mondu:" in all locales
  await expect(errorMessage).toContainText(/Mondu:/i)
})
