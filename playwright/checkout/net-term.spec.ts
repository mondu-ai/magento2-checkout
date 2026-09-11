import { test, expect, request as playwrightRequest, Page } from '@playwright/test'
import {
  addProductToCart,
  fillShippingAddress,
  handleMonduCheckout,
  placeOrder,
  proceedToCheckout,
  selectPaymentMethod,
} from '../helpers/checkout'
import { getMonduOrder } from '../helpers/api'

/**
 * Net term selection in the storefront checkout.
 *
 * Requires the shop to have more than one net term enabled under
 * Stores > Configuration > Payment Methods > Mondu > Net terms offered to buyers,
 * and the terms have to be ones the Mondu account holds for the buyer country.
 * The spec reads the offered terms from the page rather than hardcoding them, so it
 * follows whatever the shop is configured with, and only needs at least two.
 */

const NET_TERM_SELECT = '.payment-method._active .mondu-net-term select'

async function reachPaymentStep(page: Page, methodCode: string = 'mondu'): Promise<void> {
  await addProductToCart(page)
  await proceedToCheckout(page)
  await fillShippingAddress(page, {
    firstName: 'Jane',
    lastName: 'Doe',
    email: `ac.good.${Date.now()}@example.com`,
    company: process.env.BUYER_COMPANY_AUTHORIZED || 'Mondu GmbH',
    street: 'Strassmannstr. 45',
    zip: '10122',
    city: 'Berlin',
    country: 'DE',
    phone: '+493031196513',
  })
  await selectPaymentMethod(page, methodCode)
}

test('Checkout offers the enabled net terms and preselects 30 days', async ({ page }) => {
  await reachPaymentStep(page)

  const select = page.locator(NET_TERM_SELECT)
  await select.waitFor({ state: 'visible', timeout: 15_000 })

  const offered = await select.locator('option').evaluateAll((options) =>
    options.map((option) => (option as HTMLOptionElement).value)
  )

  expect(
    offered.length,
    'the selector only appears with more than one enabled term, so the shop needs at least two configured'
  ).toBeGreaterThan(1)

  // What the checkout offers has to be what the account holds for this payment
  // method and this country, the two dimensions order creation validates, or it
  // answers 422 "proposed net terms is not available for merchant". The provider
  // has already narrowed both, so the select must match its entry exactly.
  const config = await page.evaluate(() => (window as any).checkoutConfig.monduNetTerms)
  expect(offered.map(Number).sort((a, b) => a - b)).toEqual(config.byMethod.mondu.DE)

  // 30 days is the house default whenever the merchant offers it.
  if (offered.includes('30')) {
    await expect(select).toHaveValue('30')
  }
})

test('The picked net term is the one Mondu authorizes', async ({ page }) => {
  const apiContext = await playwrightRequest.newContext()

  await reachPaymentStep(page)

  const select = page.locator(NET_TERM_SELECT)
  await select.waitFor({ state: 'visible', timeout: 15_000 })

  // Deliberately not the preselected one: a term that survives to the API only
  // proves anything if it differs from the default the account would apply anyway.
  const offered = await select.locator('option').evaluateAll((options) =>
    options.map((option) => (option as HTMLOptionElement).value)
  )
  const preselected = await select.inputValue()
  const picked = offered.find((term) => term !== preselected)

  expect(picked, 'need a second term to tell the pick apart from the default').toBeTruthy()
  await select.selectOption(picked!)

  await placeOrder(page)
  const orderUuid = await handleMonduCheckout(page)

  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  expect(monduOrder.authorized_net_term).toBe(Number(picked))

  await apiContext.dispose()
})

test('The field is laid out as its own row and reads in the shop language', async ({ page }) => {
  await reachPaymentStep(page)

  const field = page.locator('.payment-method._active .mondu-net-term')
  const label = field.locator('> .label')
  const select = field.locator('select')
  await select.waitFor({ state: 'visible', timeout: 15_000 })

  // The rule that draws the method logos matches every label whose "for" starts
  // with mondu, and used to indent this one by the 71px logo padding. Measured on
  // the inner span, not the label: padding sits inside the label's own box, so its
  // bounding box does not move and only the text does.
  const textBox = await label.locator('span').boundingBox()
  const selectBox = await select.boundingBox()
  expect(
    Math.abs(textBox!.x - selectBox!.x),
    'the label text sits above the select, not indented by the logo padding'
  ).toBeLessThan(2)

  // Sized to its content rather than stretched across the method block.
  const blockBox = await page.locator('.payment-method._active .payment-method-content').boundingBox()
  expect(selectBox!.width).toBeLessThan(blockBox!.width / 2)

  // The label and the options come from the module dictionary, so a German shop
  // must not show the English source strings.
  const lang = await page.evaluate(() => document.documentElement.lang)
  if (lang.startsWith('de')) {
    await expect(label).toHaveText('Zahlungsziel')
    expect(await select.locator('option').first().innerText()).toMatch(/Tage$/)
  }

  // And the logos still render, since that rule was narrowed to fix the indent.
  const logo = await page
    .locator('.payment-method._active .payment-method-title label[for="mondu"]')
    .evaluate((el) => getComputedStyle(el).backgroundImage)
    .catch(() => 'none')
  const activeLogo = await page
    .locator('.payment-method._active .payment-method-title label')
    .evaluate((el) => getComputedStyle(el).backgroundImage)
  expect(activeLogo === 'none' ? logo : activeLogo).not.toBe('none')
})

test('Each payment method offers only its own terms, and a single one is shown too', async ({ page }) => {
  await reachPaymentStep(page, 'mondu')

  const config = await page.evaluate(() => (window as any).checkoutConfig.monduNetTerms)

  // Instalments cannot carry a term at all, so they are absent rather than empty.
  expect(config.byMethod.monduinstallment).toBeUndefined()
  expect(config.byMethod.monduinstallmentbyinvoice).toBeUndefined()

  const field = (code: string) => page.locator(`.payment-method._active .mondu-net-term`)

  for (const code of Object.keys(config.byMethod)) {
    await selectPaymentMethod(page, code)
    const offered: number[] = config.byMethod[code].DE || []
    if (!offered.length) {
      continue
    }

    const root = field(code)
    await expect(root, `${code} must show its term`).toBeVisible()

    const select = root.locator('select')
    const single = root.locator('.mondu-net-term-single')

    if (offered.length > 1) {
      // A choice is a choice.
      await expect(select).toBeVisible()
      const values = await select.locator('option').evaluateAll((o) =>
        o.map((x) => Number((x as HTMLOptionElement).value))
      )
      expect(values).toEqual(offered)
    } else {
      // One term is not a question, but the buyer is still told when it falls due.
      await expect(single, `${code} must state its only term`).toBeVisible()
      await expect(select).toBeHidden()
      expect(await single.innerText()).toContain(String(offered[0]))
    }
  }

  // The account holds 3 days for pay now only, and an invoice order on 3 days is
  // refused, so the short term must not reach invoice through the merged list.
  expect(config.byMethod.mondu?.DE ?? []).not.toContain(3)
})
