import { Page, expect } from '@playwright/test'

const BACKEND_URL = process.env.MAGENTO_BACKEND_URL || ''
const BACKEND_USER = process.env.MAGENTO_BACKEND_USERNAME || 'admin'
const BACKEND_PASS = process.env.MAGENTO_BACKEND_PASSWORD || 'admin123'

export async function loginToAdmin(page: Page): Promise<void> {
  await page.goto(BACKEND_URL)
  await page.locator('#username').fill(BACKEND_USER)
  await page.locator('#login').fill(BACKEND_PASS)
  await page.locator('.action-login').click()
  await page.waitForURL(`${BACKEND_URL}/**`, { timeout: 30_000 })
  // Dismiss any admin notification modals
  const dismissBtn = page.locator('button.action-dismiss, .modal-popup .action-close')
  if (await dismissBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await dismissBtn.click()
  }
}

export async function navigateToOrders(page: Page): Promise<void> {
  await page.goto(`${BACKEND_URL}/sales/order/`)
  // Magento admin fetches grid data via AJAX; wait until rows actually appear in the DOM
  await page.waitForFunction(
    () => document.querySelectorAll('.data-grid tbody tr').length > 0,
    { timeout: 60_000 }
  )
}

export async function openOrderByMagentoId(page: Page, orderId: string): Promise<void> {
  await navigateToOrders(page)
  await page.locator(`tr:has(td:has-text("${orderId}")) a:has-text("View")`).click()
  await page.waitForSelector('.page-title', { timeout: 20_000 })
}

export async function openFirstOrder(page: Page): Promise<void> {
  await navigateToOrders(page)
  await page.locator('.data-grid tbody tr:first-child a:has-text("View")').click()
  await page.waitForSelector('.page-title', { timeout: 20_000 })
  // Wait for the order info table to load (loaded asynchronously via AJAX)
  await page.waitForSelector('th:has-text("Order Status")', { timeout: 20_000 }).catch(() => {})
}

/**
 * Clicks a button in the order view header, waiting for it to appear.
 *
 * Mondu's confirmation webhook moves the order out of payment_review a few seconds after
 * the storefront redirect, and Magento only renders Invoice/Ship/Edit once that happened.
 */
async function clickOrderHeaderButton(page: Page, selector: string): Promise<void> {
  const button = page.locator(selector).first()

  for (let attempt = 0; attempt < 6; attempt++) {
    if (await button.isVisible({ timeout: 5_000 }).catch(() => false)) {
      break
    }
    await page.waitForTimeout(5_000)
    await page.reload()
  }

  await button.click()
}

export async function createInvoice(page: Page): Promise<void> {
  // Click the Invoice button in the order view header.
  // 2.4.9 renders the header buttons through Ready-for-Pickup, which prefixes every
  // data-ui-id (sales-order-ready-for-pickup-*), so anchor on the stable element id.
  await clickOrderHeaderButton(
    page,
    '#order_invoice, button[title="Invoice"], button[data-ui-id$="order-invoice-button"]'
  )
  // Wait for invoice items form
  await page.waitForSelector('#invoice_item_container, .order-invoice-items', { timeout: 20_000 })
  // Submit the invoice
  await page
    .locator('button[title="Submit Invoice"], button[data-ui-id="order-items-submit-button"]')
    .first()
    .click()
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function createShipment(page: Page): Promise<void> {
  // Use the specific Ship button id to avoid matching nav links
  await clickOrderHeaderButton(
    page,
    '#order_ship, button[title="Ship"], button[data-ui-id$="order-ship-button"]'
  )
  await page.waitForSelector('#shipping-tracking-table, .order-shipping-address', {
    timeout: 20_000,
  })
  await page
    .locator('button[title="Submit Shipment"], button[data-ui-id="order-items-submit-button"]')
    .first()
    .click()
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function createCreditMemo(
  page: Page,
  qty?: number,
  _invoiceUuid?: string
): Promise<void> {
  // Click the Credit Memo button/link in the order view header
  // The Mondu invoice UUID is auto-selected from the dropdown (populated by mondumemo.phtml)
  await clickOrderHeaderButton(
    page,
    '#order_creditmemo, button[title="Credit Memo"], button[data-ui-id$="order-creditmemo-button"]'
  )
  await page.waitForSelector('#creditmemo_item_container, .order-creditmemo-items', {
    timeout: 20_000,
  })

  // Set quantity if doing partial refund
  if (qty !== undefined) {
    const qtyInput = page.locator('input[name*="qty"]').first()
    if (await qtyInput.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await qtyInput.clear()
      await qtyInput.fill(String(qty))
      await page
        .locator('button.update-button, button:has-text("Update Qty")')
        .first()
        .click()
        .catch(() => {})
      await page.waitForTimeout(1_000)
    }
  }

  // Submit credit memo — try "Refund Offline" first, then generic "Refund"
  await page
    .locator(
      'button[title="Refund Offline"], button.refund-offline, button[title="Refund"], button[data-ui-id="order-items-submit-button"]'
    )
    .first()
    .click()
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function cancelOrder(page: Page): Promise<void> {
  // Use the specific Cancel button ID to avoid matching other Cancel buttons on the page
  await clickOrderHeaderButton(page, '#order-view-cancel-button, button[title="Cancel"]')
  // Confirm dialog if present
  const confirmOk = page.locator('.modal-popup button.action-accept, .modal-popup button:has-text("OK")')
  if (await confirmOk.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await confirmOk.click()
  }
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function editOrder(page: Page): Promise<void> {
  await clickOrderHeaderButton(
    page,
    '#order_edit, button[title="Edit"], button[data-ui-id$="order-edit-button"]'
  )
  // Magento shows a confirmation dialog: "This order will be canceled and a new one created."
  const confirmOk = page.locator('.modal-popup button.action-accept, .modal-popup button:has-text("OK")')
  if (await confirmOk.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await confirmOk.click()
  }
  // Wait for redirect to the order edit/create page
  await page.waitForURL('**/sales/order_create/**', { timeout: 30_000 })
}

export async function submitEditOrder(page: Page): Promise<void> {
  await page.locator('button:has-text("Submit Order")').click()
  await page.waitForURL('**/sales/order/view/**', { timeout: 30_000 })
}

export async function selectBulkAction(
  page: Page,
  orderIncrementIds: string[],
  action: string
): Promise<void> {
  await navigateToOrders(page)

  for (const id of orderIncrementIds) {
    const row = page.locator(`tr:has(td:has-text("${id}"))`)
    await row.locator('input[type="checkbox"]').check()
  }

  await page.locator('select[name="group_action"], .action-select').selectOption({ label: action })
  await page.locator('button:has-text("Submit")').click()
  await page.waitForSelector('.message-success, .message-notice', { timeout: 60_000 })
}

const PRODUCT_SKU = process.env.MAGENTO_PRODUCT_SKU || '24-MB02'

/**
 * Creates an invoice order from the admin for a new customer, the way a merchant does it.
 * Returns the net term picked in the Mondu fields.
 */
export async function createAdminOrder(page: Page, email: string): Promise<{ netTerm: string }> {
  await loginToAdmin(page)

  await page.locator('#menu-magento-sales-sales > a').click()
  await page.locator('#menu-magento-sales-sales .item-sales-order a').click()
  await page.getByRole('button', { name: 'Create New Order' }).click()

  // The button does nothing until the order-create scripts have initialised.
  await page.waitForLoadState('networkidle')
  await page.getByRole('button', { name: 'Create New Customer' }).click()
  // Single store shops skip the store step, others ask for it first.
  const items = page.locator('#order-items')
  const storeRadio = page.locator('#order-store-selector input[type="radio"]:visible').first()
  await page
    .locator('#order-items:visible, #order-store-selector input[type="radio"]:visible')
    .first()
    .waitFor({ state: 'visible', timeout: 30_000 })
  if (await storeRadio.isVisible()) {
    await storeRadio.check()
  }
  await items.waitFor({ state: 'visible', timeout: 30_000 })

  await page.getByRole('button', { name: 'Add Products' }).click()
  const skuFilter = page.locator('#sales_order_create_search_grid_filter_sku')
  await skuFilter.fill(PRODUCT_SKU)
  await skuFilter.press('Enter')
  await waitForReload(page)
  const productRow = page.locator('#sales_order_create_search_grid_table tbody tr', { hasText: PRODUCT_SKU }).first()
  await productRow.locator('input[type="checkbox"]').check()
  await page.getByRole('button', { name: 'Add Selected Product(s) to Order' }).click()
  await waitForReload(page)
  await expect(page.locator('#order-items_grid')).toContainText(PRODUCT_SKU, { timeout: 30_000 })

  await page.locator('#email').fill(email)

  const billing = (field: string) => page.locator(`#order-billing_address_${field}`)
  await billing('firstname').fill('Jane')
  await billing('lastname').fill('Doe')
  await billing('company').fill(process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH')
  await billing('street0').fill('Strassmannstr. 45')
  await billing('country_id').selectOption('DE')
  await waitForReload(page)
  await billing('city').fill('Berlin')
  await billing('postcode').fill('10122')
  await billing('telephone').fill('+493031196513')
  await billing('telephone').blur()
  await waitForReload(page)

  await page.locator('#order-shipping-method-summary a').click()
  await waitForReload(page)
  await page.locator('#s_method_flatrate_flatrate').check()
  await waitForReload(page)

  await page.locator('#p_method_mondu').check()
  await waitForReload(page)

  const fields = page.locator('#mondu-order-create-fields')
  await expect(fields).toBeVisible()
  await fields.locator('#mondu_legal_form_category').selectOption('kapital_und_personen_gesellschaft')
  await fields.locator('#mondu_registration_id').fill('HRB 232626 B')

  const netTerm = fields.locator('#mondu_net_term')
  await expect(netTerm.locator('option[value]:not([value=""])').first()).toBeAttached({ timeout: 15_000 })
  if (!(await netTerm.inputValue())) {
    await netTerm.selectOption({ index: 1 })
  }
  const picked = await netTerm.inputValue()

  await page.locator('#submit_order_top_button').click()
  await expect(page.locator('.message-success')).toContainText('You created the order', { timeout: 60_000 })

  return { netTerm: picked }
}

export async function waitForReload(page: Page): Promise<void> {
  await page.waitForTimeout(500)
  await page
    .locator('#order-container .loading-mask, .admin__data-grid-loading-mask, body > .loading-mask')
    .first()
    .waitFor({ state: 'hidden', timeout: 30_000 })
    .catch(() => {})
}
