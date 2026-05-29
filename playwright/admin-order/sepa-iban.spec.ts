import { test, expect } from '@playwright/test'
import { loginToAdmin } from '../helpers/admin'

const BACKEND_URL = process.env.MAGENTO_BACKEND_URL || ''
const PRODUCT_SKU = '24-MB04'

test('IBAN field accepts input with spaces and normalizes', async ({ page }) => {
  test.setTimeout(120_000)

  await loginToAdmin(page)

  await page.goto(`${BACKEND_URL}/sales/order/`)
  await page.waitForSelector('.page-title', { timeout: 30_000 })
  await page.locator('button#add, button:has-text("Create New Order"), a:has-text("Create New Order")').first().click()
  await page.waitForLoadState('networkidle')

  await page.waitForSelector('#sales_order_create_customer_grid, #order-customer-selector', { timeout: 20_000 })
  await page.locator('button:has-text("Create New Customer")').click()
  await page.waitForLoadState('networkidle')

  await page.waitForSelector('text=Please select a store', { timeout: 10_000 })
  await page.locator('label:has-text("Default Store View"), span:has-text("Default Store View")').first().click()
  await page.waitForLoadState('networkidle')

  await page.waitForSelector('button:has-text("Add Products")', { timeout: 30_000 })
  await page.locator('button:has-text("Add Products")').click()
  await page.waitForSelector('#sales_order_create_search_grid', { timeout: 20_000 })

  const skuFilter = page.locator('#sales_order_create_search_grid input[name="sku"]')
  if (await skuFilter.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await skuFilter.fill(PRODUCT_SKU)
    await page.locator('#sales_order_create_search_grid button:has-text("Search")').click()
    await page.waitForLoadState('networkidle')
  }

  await page.locator('#sales_order_create_search_grid tbody tr:first-child input[type="checkbox"]').check()
  await page.locator('button:has-text("Add Selected Product(s) to Order")').click()
  await page.waitForLoadState('networkidle')
  await page.waitForSelector('#order-items_grid', { timeout: 20_000 })

  const bp = 'order[billing_address]'
  await page.locator(`input[name="${bp}[firstname]"]`).fill('Jane')
  await page.locator(`input[name="${bp}[lastname]"]`).fill('Doe')
  await page.locator(`input[name="${bp}[company]"]`).fill('Mondu GmbH')
  await page.locator(`input[name="${bp}[street][0]"]`).fill('Strassmannstr. 45')
  await page.locator(`input[name="${bp}[city]"]`).fill('Berlin')
  await page.locator(`select[name="${bp}[country_id]"]`).selectOption('DE')
  await page.waitForTimeout(1_000)
  await page.locator(`input[name="${bp}[postcode]"]`).fill('10249')
  await page.locator(`input[name="${bp}[telephone]"]`).fill('+493031196513')

  const emailField = page.locator(`input[name="${bp}[email]"], input[name="order[account][email]"]`)
  if (await emailField.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await emailField.fill(`ac.good.${Date.now()}@example.com`)
  }

  await page.locator('a:has-text("Get shipping methods and rates"), button:has-text("Get shipping methods")').first().click()
  await page.waitForTimeout(3_000)

  const shippingRadio = page.locator('#order-shipping-method-choose input[type="radio"]').first()
  if (await shippingRadio.isVisible({ timeout: 10_000 }).catch(() => false)) {
    await shippingRadio.check()
    await page.waitForTimeout(5_000)
  }

  // Select Mondu SEPA
  await page.waitForSelector('input[name="payment[method]"]', { timeout: 15_000 })
  const sepaRadio = page.locator('input[name="payment[method]"][value="mondusepa"]')
  await sepaRadio.waitFor({ state: 'visible', timeout: 10_000 })
  await sepaRadio.check()
  await page.waitForTimeout(5_000)

  // Mondu fields should be visible
  const fieldset = page.locator('#mondu-order-create-fields')
  await expect(fieldset).toBeVisible({ timeout: 10_000 })

  // Check IBAN field HTML — no pattern attribute
  const ibanInput = page.locator('#mondu_iban')
  await expect(ibanInput).toBeVisible()
  const patternAttr = await ibanInput.getAttribute('pattern')
  console.log(`IBAN pattern attribute: ${patternAttr}`)
  expect(patternAttr).toBeNull()

  // Type IBAN with spaces — oninput should strip them
  await ibanInput.fill('')
  await ibanInput.type('DE89 3704 0044 0532 0130 00')
  const ibanValue = await ibanInput.inputValue()
  console.log(`IBAN value after typing with spaces: "${ibanValue}"`)
  expect(ibanValue).toBe('DE89370400440532013000')

  // Fill remaining required fields for SEPA
  await page.evaluate(() => {
    const legalForm = document.getElementById('mondu_legal_form') as HTMLSelectElement
    if (legalForm) {
      legalForm.value = 'GmbH'
      legalForm.dispatchEvent(new Event('change', { bubbles: true }))
    }
    const netTerm = document.getElementById('mondu_net_term') as HTMLInputElement
    if (netTerm) {
      netTerm.value = '30'
      netTerm.dispatchEvent(new Event('change', { bubbles: true }))
    }
    const accountHolder = document.getElementById('mondu_account_holder') as HTMLInputElement
    if (accountHolder) {
      accountHolder.value = 'Jane Doe'
      accountHolder.dispatchEvent(new Event('change', { bubbles: true }))
    }
  })
  await page.waitForTimeout(500)

  // Submit
  await page.locator('button#submit_order_top_button, button:has-text("Submit Order")').first().click()

  const successOrError = await Promise.race([
    page.waitForURL('**/sales/order/view/**', { timeout: 60_000 }).then(() => 'success'),
    page.waitForSelector('.message-error', { timeout: 60_000 }).then(() => 'error'),
  ])

  if (successOrError === 'error') {
    const errorText = await page.locator('.message-error').first().textContent()
    console.log(`Error: ${errorText}`)
    // Fail with details
    expect(errorText).not.toContain('Invalid format')
    expect(errorText).not.toContain('required fields')
  }

  expect(successOrError).toBe('success')
  const pageTitle = await page.locator('.page-title').textContent()
  const orderNumber = pageTitle?.match(/#(\d+)/)?.[1]
  console.log(`SEPA admin order created: #${orderNumber}`)
  expect(orderNumber).toBeTruthy()
})
