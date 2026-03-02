import { test, expect, request as playwrightRequest } from '@playwright/test'
import { placeMonduOrder } from '../helpers/checkout'
import { getMonduOrder } from '../helpers/api'

test('Place order with Invoice (mondu) payment method', async ({ page, request }) => {
  const apiContext = await playwrightRequest.newContext()

  const orderUuid = await placeMonduOrder(page, 'mondu')

  await expect(page).toHaveURL(/checkout\/onepage\/success/)
  expect(orderUuid).toBeTruthy()

  const monduOrder = await getMonduOrder(apiContext, orderUuid!)
  expect(['confirmed', 'authorized', 'pending']).toContain(monduOrder.state)
  expect(monduOrder.payment_method?.type ?? monduOrder.payment_method).toMatch(/invoice|direct/i)

  await apiContext.dispose()
})
