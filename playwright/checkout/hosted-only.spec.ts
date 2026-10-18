import { test, expect } from '@playwright/test'
import {
  addProductToCart,
  defaultCustomer,
  fillShippingAddress,
  handleMonduCheckout,
  placeOrder,
  proceedToCheckout,
  selectPaymentMethod,
} from '../helpers/checkout'

// The widget is sunset: the storefront must not load widget.js or expose an SDK URL,
// and placing an order must always go through the hosted checkout redirect.
test('Checkout loads no widget SDK and redirects to hosted checkout', async ({ page }) => {
  const widgetRequests: string[] = []
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.hostname.includes('mondu') && url.pathname.endsWith('/widget.js')) widgetRequests.push(url.href)
  })

  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, defaultCustomer(process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH'))
  await selectPaymentMethod(page, 'mondu')

  const monduConfig = await page.evaluate(() => {
    const payment = (window as any).checkoutConfig?.payment ?? {}
    return Object.keys(payment)
      .filter((code) => code.startsWith('mondu'))
      .map((code) => ({ code, sdkUrl: payment[code].sdkUrl }))
  })
  expect(monduConfig.length).toBeGreaterThan(0)
  for (const method of monduConfig) {
    expect(method.sdkUrl, `${method.code} still exposes sdkUrl`).toBeUndefined()
  }
  expect(await page.evaluate(() => typeof (window as any).monduCheckout)).toBe('undefined')

  await placeOrder(page)
  const orderUuid = await handleMonduCheckout(page)

  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()
  expect(widgetRequests).toEqual([])
})
