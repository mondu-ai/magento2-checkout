import { Page, Request } from '@playwright/test'

const PRODUCT_URL = process.env.MAGENTO_PRODUCT_URL || ''

interface CustomerData {
  firstName?: string
  lastName?: string
  email?: string
  company?: string
  street?: string
  zip?: string
  city?: string
  country?: string
  phone?: string
}

function defaultCustomer(company: string, overrides: Partial<CustomerData> = {}): CustomerData {
  return {
    firstName: process.env.BUYER_FIRST_NAME || 'Jane',
    lastName: process.env.BUYER_LAST_NAME || 'Doe',
    email: process.env.BUYER_EMAIL || `ac.good.${Date.now()}@example.com`,
    company,
    street: process.env.BUYER_STREET || 'Strassmannstr. 45',
    zip: process.env.BUYER_ZIP || '10122',
    city: process.env.BUYER_CITY || 'Berlin',
    country: process.env.BUYER_COUNTRY || 'DE',
    phone: process.env.BUYER_PHONE || '+493031196513',
    ...overrides,
  }
}

export async function addProductToCart(page: Page): Promise<void> {
  await page.goto(PRODUCT_URL)
  await page.waitForLoadState('networkidle')

  // Get current cart count before adding
  const counterBefore = await page
    .locator('.counter-number, .minicart-wrapper .counter.qty')
    .textContent()
    .catch(() => '0')

  // Ensure button is ready before clicking
  const addButton = page.locator('#product-addtocart-button')
  await addButton.waitFor({ state: 'visible', timeout: 15_000 })
  await addButton.click()

  // Wait for cart update — success message or counter change confirms cart was updated
  await Promise.race([
    page.waitForSelector('.message-success', { timeout: 20_000 }),
    page.waitForFunction(
      (before) => {
        const el = document.querySelector('.counter-number, .counter.qty .counter-number')
        return el && el.textContent !== before
      },
      counterBefore,
      { timeout: 20_000 }
    ),
  ]).catch(() => {})
}

export async function proceedToCheckout(page: Page): Promise<void> {
  await page.goto(`${process.env.MAGENTO_URL?.replace(/\/$/, '')}/checkout`)
  // If Magento redirects to cart page, the cart is empty — fail fast with a useful message
  await page.waitForURL(/\/(checkout|checkout\/cart)/, { timeout: 15_000 })
  if (page.url().includes('/checkout/cart')) {
    throw new Error(`Cart is empty — Magento redirected to cart page. PRODUCT_URL: ${PRODUCT_URL}`)
  }
}

export async function fillShippingAddress(page: Page, customer: CustomerData): Promise<void> {
  // Wait for checkout shipping form to load (Magento 2 Luma)
  await page.waitForSelector('input[name="firstname"]', { timeout: 30_000 })

  // Email field (guest checkout)
  const emailField = page.locator('#customer-email')
  if (await emailField.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await emailField.fill(customer.email || '')
    // Wait for email validation debounce
    await page.waitForTimeout(800)
  }

  await page.locator('input[name="firstname"]').fill(customer.firstName || '')
  await page.locator('input[name="lastname"]').fill(customer.lastName || '')

  const companyField = page.locator('input[name="company"]')
  if (await companyField.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await companyField.fill(customer.company || '')
  }

  await page.locator('input[name="street[0]"]').fill(customer.street || '')
  await page.locator('input[name="city"]').fill(customer.city || '')

  const countrySelect = page.locator('select[name="country_id"]')
  await countrySelect.selectOption(customer.country || 'DE')

  // Postcode and phone may appear after country is selected
  await page.locator('input[name="postcode"]').fill(customer.zip || '')

  const phoneField = page.locator('input[name="telephone"]')
  if (await phoneField.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await phoneField.fill(customer.phone || '')
  }

  // Click Next on shipping address form
  await page.locator('[data-role="opc-continue"]').click()

  // Wait for payment step to appear — Magento may skip shipping selection if only 1 method
  try {
    await page.waitForSelector('.payment-method', { timeout: 20_000 })
  } catch {
    // Shipping methods need manual selection + another Next click
    await page.waitForSelector('.table-checkout-shipping-method', { timeout: 10_000 })

    const radio = page.locator('.table-checkout-shipping-method input[type="radio"]').first()
    const checked = await radio.isChecked().catch(() => false)
    if (!checked) {
      await radio.check({ force: true })
    }

    // Wait for loader, then click Next
    await page.waitForSelector('[data-role="loader"]', { state: 'hidden', timeout: 15_000 }).catch(() => {})
    await page.locator('[data-role="opc-continue"]').click()
    await page.waitForSelector('.payment-method', { timeout: 30_000 })
  }
}

export async function selectPaymentMethod(page: Page, methodCode: string): Promise<void> {
  await page.waitForSelector('.payment-method', { timeout: 30_000 })
  const radio = page.locator(`input[value="${methodCode}"]`)
  await radio.waitFor({ state: 'visible', timeout: 15_000 })
  await radio.click()

  // Wait for Magento KnockoutJS to add ._active class to the selected payment method
  await page
    .waitForFunction(
      (code) =>
        document.querySelector(`.payment-method._active input[value="${code}"]`) !== null,
      methodCode,
      { timeout: 10_000 }
    )
    .catch(() => {})
}

export async function placeOrder(page: Page): Promise<void> {
  // Click Place Order inside the active payment method (Magento adds ._active class)
  const activeBtn = page.locator('.payment-method._active button.action.primary.checkout')
  if (await activeBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await activeBtn.click()
  } else {
    // Fallback: first enabled Place Order button that isn't the minicart checkout
    await page
      .locator('button[title="Place Order"][type="submit"]:not([disabled])')
      .first()
      .click()
  }
}

export async function handleMonduCheckout(page: Page): Promise<string | null> {
  // After clicking place order, wait for redirect to Mondu hosted checkout or widget
  try {
    await Promise.race([
      // Any mondu.ai domain (hosted checkout: pay.demo.mondu.ai)
      page.waitForURL('**mondu.ai/**', { timeout: 30_000 }),
      // In-page modal/widget
      page.waitForSelector('.mondu-modal, #mondu-checkout, iframe[src*="mondu"]', {
        timeout: 30_000,
      }),
    ])
  } catch {
    throw new Error(
      `Neither hosted checkout nor modal appeared. Current URL: ${page.url()}`
    )
  }

  // Hosted checkout: any mondu.ai domain
  if (page.url().includes('mondu.ai')) {
    return await handleHostedCheckout(page)
  }

  return await handleWidgetCheckout(page)
}

async function handleHostedCheckout(page: Page): Promise<string | null> {
  const magentoHost = new URL(process.env.MAGENTO_URL || 'https://example.com').hostname

  // Capture order_uuid from the intermediate success URL before server-side redirect
  let capturedOrderUuid: string | null = null
  const requestHandler = (request: Request) => {
    const url = request.url()
    if (url.includes('/mondu/payment_checkout/success')) {
      const match = url.match(/[?&]order_uuid=([^&]+)/)
      if (match) capturedOrderUuid = match[1]
    }
  }
  page.on('request', requestHandler)

  // Click "Zahlen mit mondu" / "Pay with mondu" confirm button
  const confirmButton = page.getByRole('button', { name: /zahlen mit|pay with|bestätigen|confirm|submit/i }).first()
  await confirmButton.waitFor({ state: 'visible', timeout: 20_000 })
  await confirmButton.click({ force: true })

  // After clicking, Mondu may redirect through multiple steps.
  // Wait for navigation back to the Magento domain (any path).
  await page.waitForURL(`**${magentoHost}**`, { timeout: 90_000 })
  page.off('request', requestHandler)

  const finalUrl = page.url()
  if (!finalUrl.includes('/checkout/onepage/success')) {
    console.warn(`Landed on unexpected URL after Mondu checkout: ${finalUrl}`)
  }

  return capturedOrderUuid
}

async function handleWidgetCheckout(page: Page): Promise<string | null> {
  const magentoHost = new URL(process.env.MAGENTO_URL || 'https://example.com').hostname

  let capturedOrderUuid: string | null = null
  const requestHandler = (request: Request) => {
    const url = request.url()
    if (url.includes('/mondu/payment_checkout/success')) {
      const match = url.match(/[?&]order_uuid=([^&]+)/)
      if (match) capturedOrderUuid = match[1]
    }
  }
  page.on('request', requestHandler)

  // In-page modal — look for confirm button inside modal/iframe
  const iframe = page.frameLocator('iframe[src*="mondu"]').first()
  const confirmBtn = iframe.locator('button:has-text("Confirm"), button[type="submit"]').first()
  if (await confirmBtn.isVisible({ timeout: 10_000 })) {
    await confirmBtn.click()
  }

  await page.waitForURL(`**${magentoHost}**`, { timeout: 60_000 })
  page.off('request', requestHandler)

  return capturedOrderUuid
}

function extractOrderUuidFromUrl(url: string): string | null {
  const match = url.match(/[?&]order_uuid=([^&]+)/)
  return match ? match[1] : null
}

export async function placeMonduOrder(
  page: Page,
  methodCode: string,
  company: string = process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
  overrides: Partial<CustomerData> = {}
): Promise<string | null> {
  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, defaultCustomer(company, overrides))
  await selectPaymentMethod(page, methodCode)
  await placeOrder(page)
  return handleMonduCheckout(page)
}
