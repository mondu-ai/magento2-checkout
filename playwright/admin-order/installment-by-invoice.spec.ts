import { test, expect, request as playwrightRequest } from '@playwright/test'
import { loginToAdmin } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'

const BACKEND_URL = process.env.MAGENTO_BACKEND_URL || ''
const PRODUCT_SKU = '24-MB04'

test('Create admin order with Mondu Installment by Invoice (legal_form + installments, no IBAN)', async ({ page }) => {
  test.setTimeout(120_000)
  const apiContext = await playwrightRequest.newContext()

  await loginToAdmin(page)

  await page.goto(`${BACKEND_URL}/sales/order/`)
  await page.waitForSelector('.page-title', { timeout: 30_000 })
  await page.locator('button#add').first().click()
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

  // Select Mondu Installment by Invoice
  await page.waitForSelector('input[name="payment[method]"]', { timeout: 15_000 })
  const installmentRadio = page.locator('input[name="payment[method]"][value="monduinstallmentbyinvoice"]')
  await installmentRadio.waitFor({ state: 'visible', timeout: 10_000 })
  await installmentRadio.check()
  await page.waitForTimeout(5_000)

  // Fieldset should be visible
  const fieldset = page.locator('#mondu-order-create-fields')
  await expect(fieldset).toBeVisible({ timeout: 10_000 })

  // Verify correct fields: legal_form + number_of_installments only
  await expect(page.locator('[data-mondu-field="mondu_legal_form"]')).toBeVisible()
  await expect(page.locator('[data-mondu-field="mondu_number_of_installments"]')).toBeVisible()
  // IBAN, account_holder, net_term should be hidden
  await expect(page.locator('[data-mondu-field="mondu_iban"]')).toBeHidden()
  await expect(page.locator('[data-mondu-field="mondu_account_holder"]')).toBeHidden()
  await expect(page.locator('[data-mondu-field="mondu_net_term"]')).toBeHidden()

  // Fill required fields
  await page.evaluate(() => {
    const legalForm = document.getElementById('mondu_legal_form') as HTMLSelectElement
    if (legalForm) {
      legalForm.value = 'GmbH'
      legalForm.dispatchEvent(new Event('change', { bubbles: true }))
    }
    const installments = document.getElementById('mondu_number_of_installments') as HTMLSelectElement
    if (installments) {
      installments.value = '3'
      installments.dispatchEvent(new Event('change', { bubbles: true }))
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
    if (errorText?.includes('below the minimum') || errorText?.includes('minimum amount')) {
      console.log('Order total below minimum for installments — friendly error message confirmed')
      expect(errorText).toContain('below the minimum')
      expect(errorText).not.toContain('gross_amount_cents')
      return
    }
    throw new Error(`Order creation failed: ${errorText}`)
  }

  const pageTitle = await page.locator('.page-title').textContent()
  const orderNumber = pageTitle?.match(/#(\d+)/)?.[1]
  expect(orderNumber).toBeTruthy()
  console.log(`Installment-by-invoice admin order created: #${orderNumber}`)

  const comments = await page.locator('.note-list-comment').allTextContents()
  const monduComment = comments.find(c => c.includes('Mondu:'))
  const monduUuid = monduComment?.match(/uuid\s+([a-f0-9-]+)/i)?.[1]
    ?? monduComment?.match(/id\s+([a-f0-9-]+)/i)?.[1]

  if (monduUuid) {
    const monduOrder = await getMonduOrder(apiContext, monduUuid)
    console.log(`Mondu order state: ${monduOrder.state}, payment: ${JSON.stringify(monduOrder.payment_method)}`)
    expect(monduOrder.payment_method).toBe('installment_by_invoice')
  }

  await apiContext.dispose()
})
