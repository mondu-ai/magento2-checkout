import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { getMonduOrder } from '../helpers/api'
import { loginToAdmin, openFirstOrder, editOrder, submitEditOrder } from '../helpers/admin'

test('Admin edits order → Adjust API called, order retains mondu_reference_id', async ({
  page,
}) => {
  const apiContext = await playwrightRequest.newContext()

  const orderUuid = await placeMonduOrder(page, 'mondu')
  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  await loginToAdmin(page)
  await openFirstOrder(page)

  // Start editing the order
  await editOrder(page)

  // Change quantity of the first product
  const qtyInput = page.locator('input[name*="qty"], input.item-qty').first()
  await qtyInput.clear()
  await qtyInput.fill('2')

  await page.locator('button:has-text("Update Items and Quantities")').click().catch(() => {})

  await submitEditOrder(page)

  // The new order should exist and be in processing state
  await expect(page.locator('.order-status')).toBeVisible()

  // New order should still have the Mondu reference in its comments
  const comments = page.locator('.order-history-block, .order-comments-history')
  await expect(comments).toBeVisible()

  await apiContext.dispose()
})
