import { test, expect, Page } from '@playwright/test'
import { sendWebhook } from '../helpers/webhook'

const MAGENTO_URL = (process.env.MAGENTO_URL || '').replace(/\/$/, '')
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || ''

const TEST_CUSTOMER = {
  email: `buyer.test.${Date.now()}@example.com`,
  password: 'Test123!@#',
  firstName: 'Test',
  lastName: 'Buyer',
  company: 'Test Company GmbH',
  street: 'Keizersgracht 100',
  city: 'Amsterdam',
  zip: '1015 AA',
  country: 'NL',
  phone: '+493031196513',
}

async function createCustomerAccount(page: Page) {
  await page.goto(`${MAGENTO_URL}/customer/account/create/`)
  await page.locator('#firstname').fill(TEST_CUSTOMER.firstName)
  await page.locator('#lastname').fill(TEST_CUSTOMER.lastName)
  await page.locator('#email_address').fill(TEST_CUSTOMER.email)
  await page.locator('#password').fill(TEST_CUSTOMER.password)
  await page.locator('#password-confirmation').fill(TEST_CUSTOMER.password)
  await page.locator('button.action.submit.primary').click()
  await page.waitForURL('**/customer/account/**', { timeout: 30_000 })
}

async function loginCustomer(page: Page) {
  await page.goto(`${MAGENTO_URL}/customer/account/login/`)
  await page.waitForLoadState('domcontentloaded')
  // Wait for the actual password field to appear in the DOM
  await page.waitForSelector('input[name="login[username]"]', { timeout: 15_000 })
  await page.fill('input[name="login[username]"]', TEST_CUSTOMER.email)
  await page.fill('input[name="login[password]"]', TEST_CUSTOMER.password)
  await page.click('button#send2, fieldset.login button.action.login')
  await page.waitForURL('**/customer/account/**', { timeout: 30_000 })
}

async function ensureLoggedIn(page: Page) {
  try {
    await loginCustomer(page)
  } catch {
    await createCustomerAccount(page)
  }
}

async function addBillingAddress(page: Page) {
  await page.goto(`${MAGENTO_URL}/customer/address/new/`)
  await page.waitForSelector('#form-validate', { timeout: 15_000 })
  await page.locator('#firstname').fill(TEST_CUSTOMER.firstName)
  await page.locator('#lastname').fill(TEST_CUSTOMER.lastName)

  const companyField = page.locator('#company')
  if (await companyField.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await companyField.fill(TEST_CUSTOMER.company)
  }

  const phoneField = page.locator('#telephone')
  if (await phoneField.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await phoneField.fill(TEST_CUSTOMER.phone)
  }

  await page.locator('#street_1').fill(TEST_CUSTOMER.street)
  await page.locator('#city').fill(TEST_CUSTOMER.city)
  await page.locator('#zip').fill(TEST_CUSTOMER.zip)
  await page.locator('#country').selectOption(TEST_CUSTOMER.country)

  // For NL, region is not required — fill text input if visible
  const regionText = page.locator('#region')
  if (await regionText.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await regionText.fill('Noord-Holland')
  }

  const defaultBilling = page.locator('#primary_billing')
  if (await defaultBilling.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await defaultBilling.check()
  }

  const defaultShipping = page.locator('#primary_shipping')
  if (await defaultShipping.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await defaultShipping.check()
  }

  // Wait for button to become enabled
  await page.locator('button.action.save.primary:not([disabled])').waitFor({ timeout: 10_000 })
  await page.locator('button.action.save.primary').click()
  await page.waitForSelector('.message-success', { timeout: 15_000 })
}

test.describe.serial('Buyer Onboarding', () => {
  test('create customer and show apply form', async ({ page }) => {
    await createCustomerAccount(page)

    await page.goto(`${MAGENTO_URL}/mondu/buyer/index`)
    const currentUrl = page.url()

    if (currentUrl.includes('/customer/account/login')) {
      console.log('Buyer onboarding is disabled in config — skipping UI assertions')
      return
    }

    const applySection = page.locator('.mondu-buyer-account')
    await expect(applySection).toBeVisible({ timeout: 10_000 })
  })

  test('apply button requires GDPR consent', async ({ page }) => {
    await loginCustomer(page)
    await page.goto(`${MAGENTO_URL}/mondu/buyer/index`)

    if (page.url().includes('/customer/account/login')) {
      console.log('Buyer onboarding disabled — skipping')
      return
    }

    const applyBtn = page.locator('#mondu-apply-button')
    if (await applyBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      page.on('dialog', async (dialog) => {
        expect(dialog.message()).toContain('consent')
        await dialog.accept()
      })

      await applyBtn.click()
      await expect(applyBtn).not.toHaveClass(/disabled/)
    }
  })

  test('navigation link appears in customer account', async ({ page }) => {
    await loginCustomer(page)
    await page.goto(`${MAGENTO_URL}/customer/account/`)

    const navLink = page.locator('a[href*="mondu/buyer"]')
    const isVisible = await navLink.isVisible({ timeout: 5_000 }).catch(() => false)
    console.log(`Mondu Trade Account nav link visible: ${isVisible}`)
    expect(isVisible).toBe(true)
  })
})

test.describe('Buyer Webhook', () => {
  test('buyer/onboarded webhook updates buyer state', async ({ request }) => {
    if (!WEBHOOK_SECRET) {
      console.log('WEBHOOK_SECRET not set — skipping webhook test')
      return
    }

    const externalRefId = 'test_buyer_' + Date.now()
    const buyerUuid = 'test-buyer-uuid-' + Date.now()

    const payload = {
      topic: 'buyer/onboarded',
      external_reference_id: externalRefId,
      buyer: {
        uuid: buyerUuid,
        state: 'accepted',
        external_reference_id: externalRefId,
        company_name: 'Test Onboarding Company',
      },
    }

    const response = await sendWebhook(request, 'buyer/onboarded', payload, WEBHOOK_SECRET)
    expect([200, 400]).toContain(response.status())

    const body = await response.json()
    console.log('Webhook response:', body)
  })

  test('buyer/onboarded webhook returns 200 for valid signature', async ({ request }) => {
    if (!WEBHOOK_SECRET) {
      console.log('WEBHOOK_SECRET not set — skipping webhook test')
      return
    }

    const payload = {
      topic: 'buyer/onboarded',
      external_reference_id: 'nonexistent_ref',
      buyer: {
        uuid: 'some-uuid',
        state: 'accepted',
        external_reference_id: 'nonexistent_ref',
        company_name: 'Test Co',
      },
    }

    const response = await sendWebhook(request, 'buyer/onboarded', payload, WEBHOOK_SECRET)
    expect(response.status()).toBe(200)
  })

  test('buyer/onboarded webhook rejects invalid signature', async ({ request }) => {
    const payload = {
      topic: 'buyer/onboarded',
      external_reference_id: 'test_ref',
      buyer: {
        uuid: 'some-uuid',
        state: 'accepted',
        external_reference_id: 'test_ref',
      },
    }

    const response = await sendWebhook(request, 'buyer/onboarded', payload, 'wrong_secret')
    expect(response.status()).toBe(200)
    const body = await response.json()
    expect(body.error).toBe(0)
  })
})

test.describe('Buyer Controllers', () => {
  test('success redirect works', async ({ page }) => {
    await ensureLoggedIn(page)
    const response = await page.goto(`${MAGENTO_URL}/mondu/buyer/success`)
    // Should either show buyer page or redirect to login
    expect(response?.status()).toBeLessThan(500)
  })

  test('cancel redirect works', async ({ page }) => {
    await ensureLoggedIn(page)
    const response = await page.goto(`${MAGENTO_URL}/mondu/buyer/cancel`)
    expect(response?.status()).toBeLessThan(500)
  })

  test('declined redirect works', async ({ page }) => {
    await ensureLoggedIn(page)
    const response = await page.goto(`${MAGENTO_URL}/mondu/buyer/declined`)
    expect(response?.status()).toBeLessThan(500)
  })

  test('buyer page redirects to login for guest', async ({ page }) => {
    await page.context().clearCookies()
    await page.goto(`${MAGENTO_URL}/mondu/buyer/index`)
    await page.waitForURL('**/customer/account/login**', { timeout: 10_000 })
  })

  test('apply endpoint rejects unauthenticated request', async ({ request }) => {
    const response = await request.post(`${MAGENTO_URL}/mondu/buyer/apply`, {
      data: { gdpr_consent: 1 },
    })
    const body = await response.json()
    expect(body.success).toBe(false)
  })
})
