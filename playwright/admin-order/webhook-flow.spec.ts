import { test, expect, request as playwrightRequest } from '@playwright/test'
import { loginToAdmin } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'
import { sendWebhook, buildOrderPendingPayload, buildOrderConfirmedPayload, buildOrderDeclinedPayload } from '../helpers/webhook'

const BACKEND_URL = process.env.MAGENTO_BACKEND_URL || ''
const PRODUCT_SKU = '24-MB04'

async function createAdminOrder(
  page: any,
  email: string
): Promise<{ orderNumber: string; monduUuid: string }> {
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
    await emailField.fill(email)
  }

  await page.locator('a:has-text("Get shipping methods and rates"), button:has-text("Get shipping methods")').first().click()
  await page.waitForTimeout(3_000)

  const shippingRadio = page.locator('#order-shipping-method-choose input[type="radio"]').first()
  if (await shippingRadio.isVisible({ timeout: 10_000 }).catch(() => false)) {
    await shippingRadio.check()
    await page.waitForTimeout(5_000)
  }

  await page.waitForSelector('input[name="payment[method]"]', { timeout: 15_000 })
  const monduRadio = page.locator('input[name="payment[method]"][value="mondu"]')
  await monduRadio.waitFor({ state: 'visible', timeout: 10_000 })
  await monduRadio.check()
  await page.waitForTimeout(5_000)

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

  const comments = await page.locator('.note-list-comment').allTextContents()
  const monduComment = comments.find((c: string) => c.includes('Mondu:'))
  const monduUuid = monduComment?.match(/uuid\s+([a-f0-9-]+)/i)?.[1]
    ?? monduComment?.match(/id\s+([a-f0-9-]+)/i)?.[1]
  expect(monduUuid).toBeTruthy()

  return { orderNumber: orderNumber!, monduUuid: monduUuid! }
}

async function getOrderStatusFromView(page: any, orderNumber: string): Promise<string> {
  await page.goto(`${BACKEND_URL}/sales/order/`)
  await page.waitForFunction(
    () => document.querySelectorAll('.data-grid tbody tr').length > 0,
    { timeout: 60_000 }
  )
  const row = page.locator(`.data-grid tbody tr:has(td:has-text("${orderNumber}"))`)
  await row.locator('a:has-text("View")').click()
  await page.waitForSelector('.page-title', { timeout: 20_000 })
  await page.waitForTimeout(1_000)

  return await page.evaluate(() => {
    const rows = document.querySelectorAll('.order-information-table tr')
    for (const row of rows) {
      const th = row.querySelector('th')
      if (th?.textContent?.includes('Order Status')) {
        return row.querySelector('td')?.textContent?.trim() || 'unknown'
      }
    }
    return 'unknown'
  })
}

test('Webhook flow: pending → authorized → confirmed → Processing', async ({ page }) => {
  test.setTimeout(180_000)
  const apiContext = await playwrightRequest.newContext()

  await loginToAdmin(page)

  const { orderNumber, monduUuid } = await createAdminOrder(page, `ac.good.${Date.now()}@example.com`)
  console.log(`Created order #${orderNumber}, UUID: ${monduUuid}`)

  // Send pending webhook
  const pendingPayload = buildOrderPendingPayload(monduUuid, orderNumber)
  const pendingRes = await sendWebhook(apiContext, 'order/pending', pendingPayload)
  console.log(`Pending webhook: ${pendingRes.status()}`)
  expect(pendingRes.status()).toBe(200)

  // Verify Payment Review
  await page.waitForTimeout(2_000)
  let status = await getOrderStatusFromView(page, orderNumber)
  console.log(`After pending: ${status}`)
  expect(status).toBe('Payment Review')

  // Send authorized webhook
  const authorizedPayload = {
    topic: 'order/authorized',
    order_uuid: monduUuid,
    external_reference_id: orderNumber,
    order_state: 'authorized',
  }
  const authRes = await sendWebhook(apiContext, 'order/authorized', authorizedPayload)
  console.log(`Authorized webhook: ${authRes.status()}`)
  expect(authRes.status()).toBe(200)

  // Verify Pending Buyer Confirmation
  await page.waitForTimeout(2_000)
  status = await getOrderStatusFromView(page, orderNumber)
  console.log(`After authorized: ${status}`)
  expect(status).toBe('Pending Buyer Confirmation')

  // Send confirmed webhook
  const confirmedPayload = buildOrderConfirmedPayload(monduUuid, orderNumber)
  const confirmRes = await sendWebhook(apiContext, 'order/confirmed', confirmedPayload)
  console.log(`Confirmed webhook: ${confirmRes.status()}`)
  expect(confirmRes.status()).toBe(200)

  // Verify Processing
  await page.waitForTimeout(2_000)
  status = await getOrderStatusFromView(page, orderNumber)
  console.log(`After confirmed: ${status}`)
  expect(status).toBe('Processing')

  // Verify UpdateExternalInfo was sent
  const finalOrder = await getMonduOrder(apiContext, monduUuid)
  console.log(`Mondu external_reference_id: ${finalOrder.external_reference_id}`)
  expect(finalOrder.external_reference_id).toBe(orderNumber)

  await apiContext.dispose()
})

test('Webhook flow: declined → Canceled', async ({ page }) => {
  test.setTimeout(180_000)
  const apiContext = await playwrightRequest.newContext()

  await loginToAdmin(page)

  const { orderNumber, monduUuid } = await createAdminOrder(page, `ac.good.${Date.now()}@example.com`)
  console.log(`Created order #${orderNumber}, UUID: ${monduUuid}`)

  // Send pending first
  const pendingPayload = buildOrderPendingPayload(monduUuid, orderNumber)
  await sendWebhook(apiContext, 'order/pending', pendingPayload)

  // Send declined
  const declinedPayload = buildOrderDeclinedPayload(monduUuid, orderNumber, 'risk_policy')
  const declinedRes = await sendWebhook(apiContext, 'order/declined', declinedPayload)
  console.log(`Declined webhook: ${declinedRes.status()}`)
  expect(declinedRes.status()).toBe(200)

  // Verify Canceled
  await page.waitForTimeout(2_000)
  const status = await getOrderStatusFromView(page, orderNumber)
  console.log(`After declined: ${status}`)
  expect(status).toBe('Canceled')

  await apiContext.dispose()
})
