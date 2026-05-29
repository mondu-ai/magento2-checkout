import { test, expect, request as playwrightRequest } from '@playwright/test'
import { loginToAdmin } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'

const BACKEND_URL = process.env.MAGENTO_BACKEND_URL || ''
const PRODUCT_SKU = '24-MB04'

test('Create admin order with Mondu Invoice (async flow)', async ({ page }) => {
  test.setTimeout(120_000)
  const apiContext = await playwrightRequest.newContext()

  // 1. Login to admin
  await loginToAdmin(page)

  // 2. Navigate to Sales → Orders → Create New Order
  await page.goto(`${BACKEND_URL}/sales/order/`)
  await page.waitForSelector('.page-title', { timeout: 30_000 })
  await page.locator('button#add, button:has-text("Create New Order"), a:has-text("Create New Order")').first().click()
  await page.waitForLoadState('networkidle')

  // 3. Select customer → Create New Customer
  await page.waitForSelector('#sales_order_create_customer_grid, #order-customer-selector', { timeout: 20_000 })
  await page.locator('button:has-text("Create New Customer")').click()
  await page.waitForLoadState('networkidle')

  // 4. Select store → Default Store View
  await page.waitForSelector('text=Please select a store', { timeout: 10_000 })
  await page.locator('label:has-text("Default Store View"), span:has-text("Default Store View")').first().click()
  await page.waitForLoadState('networkidle')

  // 5. Wait for order form, add product
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

  // 6. Fill billing address
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

  // Email
  const emailField = page.locator(`input[name="${bp}[email]"], input[name="order[account][email]"]`)
  if (await emailField.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await emailField.fill(`ac.good.${Date.now()}@example.com`)
  }

  // 7. Get shipping methods & select
  await page.locator('a:has-text("Get shipping methods and rates"), button:has-text("Get shipping methods")').first().click()
  await page.waitForTimeout(3_000)

  const shippingRadio = page.locator('#order-shipping-method-choose input[type="radio"]').first()
  if (await shippingRadio.isVisible({ timeout: 10_000 }).catch(() => false)) {
    await shippingRadio.check()
    // Selecting shipping triggers AJAX reload of payment block
    await page.waitForTimeout(5_000)
  }

  // 8. Select Mondu Invoice payment method
  // After AJAX reload, wait for payment methods to re-appear
  await page.waitForSelector('input[name="payment[method]"]', { timeout: 15_000 })
  const monduRadio = page.locator('input[name="payment[method]"][value="mondu"]')
  await monduRadio.waitFor({ state: 'visible', timeout: 10_000 })
  await monduRadio.check()

  // Wait for payment selection AJAX reload (Magento reloads billing_method block)
  await page.waitForTimeout(5_000)

  // 9. Verify MonduFieldsConfig is set (init script outside AJAX block)
  const debugInfo = await page.evaluate(() => {
    return {
      hasConfig: !!(window as any).MonduFieldsConfig,
      configKeys: Object.keys((window as any).MonduFieldsConfig || {}),
      fieldsetVisible: document.getElementById('mondu-order-create-fields')?.style.display,
    }
  })
  console.log('Debug info:', JSON.stringify(debugInfo))

  // Fieldset should auto-show via Ajax.Responders after payment method selection
  const fieldsetVisible = await page.locator('#mondu-order-create-fields').isVisible().catch(() => false)
  console.log(`Fieldset auto-visible: ${fieldsetVisible}`)

  // 10. Fill legal_form and net_term
  await page.evaluate(() => {
    const legalForm = document.getElementById('mondu_legal_form') as HTMLSelectElement
    const netTerm = document.getElementById('mondu_net_term') as HTMLInputElement
    if (legalForm) {
      legalForm.value = 'GmbH'
      legalForm.dispatchEvent(new Event('change', { bubbles: true }))
    }
    if (netTerm) {
      netTerm.value = '30'
      netTerm.dispatchEvent(new Event('change', { bubbles: true }))
    }
  })
  await page.waitForTimeout(500)

  // Verify fields are set
  const legalFormValue = await page.evaluate(() => (document.getElementById('mondu_legal_form') as HTMLSelectElement)?.value)
  const netTermValue = await page.evaluate(() => (document.getElementById('mondu_net_term') as HTMLInputElement)?.value)
  console.log(`Legal form: ${legalFormValue}, Net term: ${netTermValue}`)
  expect(legalFormValue).toBe('GmbH')
  expect(netTermValue).toBe('30')

  // 11. Submit order
  await page.locator('button#submit_order_top_button, button:has-text("Submit Order")').first().click()

  // 12. Wait for result
  const successOrError = await Promise.race([
    page.waitForURL('**/sales/order/view/**', { timeout: 60_000 }).then(() => 'success'),
    page.waitForSelector('.message-error', { timeout: 60_000 }).then(() => 'error'),
  ])

  if (successOrError === 'error') {
    const errorText = await page.locator('.message-error').first().textContent()
    throw new Error(`Order creation failed: ${errorText}`)
  }

  // 13. Extract order number
  const pageTitle = await page.locator('.page-title').textContent()
  const orderNumber = pageTitle?.match(/#(\d+)/)?.[1]
  expect(orderNumber).toBeTruthy()
  console.log(`Admin order created: #${orderNumber}`)

  // 14. Find Mondu UUID from order comments
  const comments = await page.locator('.note-list-comment').allTextContents()
  const monduComment = comments.find(c => c.includes('Mondu:'))
  console.log(`Mondu comment: ${monduComment}`)
  expect(monduComment).toBeTruthy()

  const monduUuid = monduComment?.match(/uuid\s+([a-f0-9-]+)/i)?.[1]
    ?? monduComment?.match(/id\s+([a-f0-9-]+)/i)?.[1]
  console.log(`Mondu UUID: ${monduUuid}`)

  if (monduUuid) {
    // 15. Verify via Mondu API
    const monduOrder = await getMonduOrder(apiContext, monduUuid)
    expect(monduOrder).toBeTruthy()
    console.log(`Mondu order state: ${monduOrder.state}, payment: ${JSON.stringify(monduOrder.payment_method)}`)
  }

  await apiContext.dispose()
})
