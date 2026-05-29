import { test, expect, request as playwrightRequest } from '@playwright/test'
import { loginToAdmin } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'

const BACKEND_URL = process.env.MAGENTO_BACKEND_URL || ''
const PRODUCT_SKU = '24-MB04'

test('Create admin order with Mondu PayNow (no extra fields required)', async ({ page }) => {
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

  await page.waitForSelector('input[name="payment[method]"]', { timeout: 15_000 })
  const payNowRadio = page.locator('input[name="payment[method]"][value="mondupaynow"]')
  await payNowRadio.waitFor({ state: 'visible', timeout: 10_000 })
  await payNowRadio.check()
  await page.waitForTimeout(5_000)

  // PayNow requires no extra fields (no legal_form, no net_term)
  // Mondu fieldset should be hidden or show no required fields
  const fieldsetVisible = await page.locator('#mondu-order-create-fields').isVisible().catch(() => false)
  console.log(`Mondu fieldset visible for PayNow: ${fieldsetVisible}`)

  await page.locator('button#submit_order_top_button, button:has-text("Submit Order")').first().click()

  const successOrError = await Promise.race([
    page.waitForURL('**/sales/order/view/**', { timeout: 60_000 }).then(() => 'success'),
    page.waitForSelector('.message-error', { timeout: 60_000 }).then(() => 'error'),
  ])

  if (successOrError === 'error') {
    const errorText = await page.locator('.message-error').first().textContent()
    throw new Error(`Order creation failed: ${errorText}`)
  }

  const pageTitle = await page.locator('.page-title').textContent()
  const orderNumber = pageTitle?.match(/#(\d+)/)?.[1]
  expect(orderNumber).toBeTruthy()
  console.log(`PayNow admin order created: #${orderNumber}`)

  const comments = await page.locator('.note-list-comment').allTextContents()
  const monduComment = comments.find(c => c.includes('Mondu:'))
  console.log(`Mondu comment: ${monduComment}`)
  expect(monduComment).toBeTruthy()

  const monduUuid = monduComment?.match(/uuid\s+([a-f0-9-]+)/i)?.[1]
    ?? monduComment?.match(/id\s+([a-f0-9-]+)/i)?.[1]
  console.log(`Mondu UUID: ${monduUuid}`)

  if (monduUuid) {
    const monduOrder = await getMonduOrder(apiContext, monduUuid)
    expect(monduOrder).toBeTruthy()
    console.log(`Mondu order state: ${monduOrder.state}, payment: ${JSON.stringify(monduOrder.payment_method)}`)
    expect(monduOrder.payment_method).toBe('pay_now')
  }

  await apiContext.dispose()
})
