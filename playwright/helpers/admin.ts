import { Page } from '@playwright/test'

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

export async function createInvoice(page: Page): Promise<void> {
  // Click the Invoice button in the order view header
  await page
    .locator('button[data-ui-id="order-view-invoice-button"], a[data-ui-id="order-view-invoice-button"]')
    .first()
    .click()
  // Wait for invoice items form
  await page.waitForSelector('#invoice_item_container, .order-invoice-items', { timeout: 20_000 })
  // Submit the invoice
  await page
    .locator('button[data-ui-id="order-invoice-view-save-button"]')
    .click()
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function createShipment(page: Page): Promise<void> {
  // Use the specific Ship button via data-ui-id to avoid matching nav links
  await page
    .locator('button[data-ui-id="order-view-ship-button"], a[data-ui-id="order-view-ship-button"]')
    .first()
    .click()
  await page.waitForSelector('#shipping-tracking-table, .order-shipping-address', {
    timeout: 20_000,
  })
  await page.locator('button[data-ui-id="order-shipment-view-save-button"]').click()
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function createCreditMemo(
  page: Page,
  qty?: number,
  _invoiceUuid?: string
): Promise<void> {
  // Click the Credit Memo button/link in the order view header
  // The Mondu invoice UUID is auto-selected from the dropdown (populated by mondumemo.phtml)
  await page
    .locator(
      'button[data-ui-id="order-view-creditmemo-button"], a[data-ui-id="order-view-creditmemo-button"]'
    )
    .first()
    .click()
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
      'button[data-ui-id="order-creditmemo-view-save-button"], button.refund-offline, button:has-text("Refund Offline"), button:has-text("Refund")'
    )
    .first()
    .click()
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function cancelOrder(page: Page): Promise<void> {
  // Use the specific Cancel button ID to avoid matching other Cancel buttons on the page
  await page.locator('#order-view-cancel-button').click()
  // Confirm dialog if present
  const confirmOk = page.locator('.modal-popup button.action-accept, .modal-popup button:has-text("OK")')
  if (await confirmOk.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await confirmOk.click()
  }
  await page.waitForSelector('.message-success', { timeout: 30_000 })
}

export async function editOrder(page: Page): Promise<void> {
  await page.locator('#order-view-edit-button, button[data-ui-id="order-view-edit-button"]').first().click()
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
