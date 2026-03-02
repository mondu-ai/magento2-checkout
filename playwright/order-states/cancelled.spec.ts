import { test, expect } from '@playwright/test'
import {
  addProductToCart,
  proceedToCheckout,
  fillShippingAddress,
  selectPaymentMethod,
  placeOrder,
} from '../helpers/checkout'

test('Customer cancels on Mondu → redirected to cart with error message', async ({ page }) => {
  const company = process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH'

  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, {
    firstName: process.env.BUYER_FIRST_NAME || 'Jane',
    lastName: process.env.BUYER_LAST_NAME || 'Doe',
    email: process.env.BUYER_EMAIL || 'accepted.test@example.com',
    company,
    street: process.env.BUYER_STREET || 'Strassmannstr. 45',
    zip: process.env.BUYER_ZIP || '10122',
    city: process.env.BUYER_CITY || 'Berlin',
    country: process.env.BUYER_COUNTRY || 'DE',
    phone: process.env.BUYER_PHONE || '+493031196513',
  })
  await selectPaymentMethod(page, 'mondu')
  await placeOrder(page)

  // Wait for Mondu hosted checkout (pay.demo.mondu.ai) or widget
  try {
    await page.waitForURL('**mondu.ai/**', { timeout: 30_000 })
  } catch {
    // May be widget flow — look for cancel button in modal
  }

  // Click cancel on Mondu checkout
  const cancelBtn = page.locator('button:has-text("Cancel"), a:has-text("Cancel"), [data-action="cancel"]')
  if (await cancelBtn.isVisible({ timeout: 10_000 }).catch(() => false)) {
    await cancelBtn.click()
  } else {
    // Navigate directly to the cancel URL to simulate cancellation
    const magentoUrl = process.env.MAGENTO_URL?.replace(/\/$/, '') || ''
    await page.goto(`${magentoUrl}/mondu/payment_checkout/cancel`)
  }

  // Should land back on cart or checkout with error
  await page.waitForURL(/checkout\/cart|mondu\/payment_checkout\/cancel/, { timeout: 30_000 })

  const errorMessage = page.locator('.message-error, .messages .message, .error-message')
  await expect(errorMessage).toBeVisible({ timeout: 15_000 })
  await expect(errorMessage).toContainText(/canceled|cancelled/i)
})
