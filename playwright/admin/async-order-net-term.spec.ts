import { test, expect, request as playwrightRequest, Page } from '@playwright/test'
import { loginToAdmin } from '../helpers/admin'
import { getMonduOrder } from '../helpers/api'

/**
 * Net term of an order placed from the admin (async flow).
 *
 * The order is created with create_async, whose answer comes before Mondu has
 * decided and carries no authorized term. Once Mondu sends order/authorized the
 * module reads the term from the API, so the merchant finds the term to invoice
 * on in the order view and in the Mondu orders grid.
 *
 * Requires admin order creation to be switched on and a Mondu account that
 * holds an async net term for DE. The webhook must reach the shop.
 */

const PRODUCT_SKU = process.env.MAGENTO_PRODUCT_SKU || '24-MB02'

async function createAdminOrder(page: Page, email: string): Promise<{ netTerm: string }> {
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

async function waitForReload(page: Page): Promise<void> {
  await page.waitForTimeout(500)
  await page
    .locator('#order-container .loading-mask, .admin__data-grid-loading-mask, body > .loading-mask')
    .first()
    .waitFor({ state: 'hidden', timeout: 30_000 })
    .catch(() => {})
}

test('An admin order shows the term Mondu authorized once order/authorized arrives', async ({ page }) => {
  test.setTimeout(240_000)
  const apiContext = await playwrightRequest.newContext()

  const { netTerm } = await createAdminOrder(page, `ac.good.${Date.now()}@example.com`)
  expect(netTerm, 'the account must hold an async term for DE').toBeTruthy()

  // The order view, right after the order was created.
  const comment = await page.locator('#order_history_block').innerText()
  const orderUuid = comment.match(/uuid ([0-9a-f-]{36})/)?.[1]
  expect(orderUuid, 'the async order comment carries the Mondu uuid').toBeTruthy()
  const orderUrl = page.url()

  // Mondu decides on its own schedule; the grid shows the authorized term only,
  // so it fills in once the webhook stored it.
  let monduOrder: any = null
  await expect
    .poll(
      async () => {
        monduOrder = await getMonduOrder(apiContext, orderUuid!)
        return monduOrder.authorized_net_term ?? null
      },
      { timeout: 90_000, intervals: [3_000] }
    )
    .not.toBeNull()

  await page.locator('#menu-mondu-mondu-log > a').click()
  await page.waitForURL(/mondu\/log/, { timeout: 30_000 })
  const row = page.locator('.data-grid tbody tr', { hasText: orderUuid! })
  await row.waitFor({ state: 'visible', timeout: 60_000 })
  const headers = await page.locator('.data-grid thead th').allInnerTexts()
  const termColumn = headers.findIndex((header) => header.trim() === 'Payment term')
  expect(termColumn, 'the grid has a Payment term column').toBeGreaterThanOrEqual(0)

  await expect
    .poll(
      async () => {
        await page.reload()
        return (await row.locator('td').nth(termColumn).innerText()).trim()
      },
      { timeout: 90_000, intervals: [5_000], message: 'order/authorized must store the authorized term' }
    )
    .toBe(String(monduOrder.authorized_net_term))

  await page.goto(orderUrl)
  await expect(page.locator('.order-payment-method tr', { hasText: 'Payment term' })).toContainText(
    `${monduOrder.authorized_net_term} days`
  )

  await apiContext.dispose()
})
