import { APIRequestContext } from '@playwright/test'

const API_URL = process.env.API_URL || 'https://api.demo.mondu.ai/api/v1'
const API_TOKEN = process.env.API_TOKEN || ''

function headers() {
  return {
    'Api-Token': API_TOKEN,
    'Content-Type': 'application/json',
  }
}

export async function getMonduOrder(apiContext: APIRequestContext, orderUuid: string) {
  const response = await apiContext.get(`${API_URL}/orders/${orderUuid}`, { headers: headers() })
  if (!response.ok()) {
    throw new Error(`Failed to get Mondu order ${orderUuid}: ${response.status()}`)
  }
  const body = await response.json()
  return body.order ?? body
}

export async function checkMonduOrderState(
  apiContext: APIRequestContext,
  orderUuid: string,
  expectedState: string,
  retries = 5,
  delayMs = 2000
): Promise<void> {
  for (let i = 0; i < retries; i++) {
    const order = await getMonduOrder(apiContext, orderUuid)
    if (order.state === expectedState) return
    if (i < retries - 1) {
      await new Promise((resolve) => setTimeout(resolve, delayMs))
    }
  }
  const order = await getMonduOrder(apiContext, orderUuid)
  throw new Error(`Expected Mondu order state '${expectedState}', got '${order.state}'`)
}

export async function getLastMonduOrder(apiContext: APIRequestContext) {
  // Mondu API returns orders sorted by created_at desc by default
  const response = await apiContext.get(`${API_URL}/orders?page=1`, {
    headers: headers(),
  })
  if (!response.ok()) {
    throw new Error(`Failed to get Mondu orders: ${response.status()}`)
  }
  const body = await response.json()
  const orders = body.orders ?? body
  return Array.isArray(orders) ? orders[0] : orders
}

export async function getMonduInvoice(
  apiContext: APIRequestContext,
  orderUuid: string,
  invoiceUuid: string
) {
  const response = await apiContext.get(
    `${API_URL}/orders/${orderUuid}/invoices/${invoiceUuid}`,
    { headers: headers() }
  )
  if (!response.ok()) {
    throw new Error(
      `Failed to get Mondu invoice ${invoiceUuid} for order ${orderUuid}: ${response.status()}`
    )
  }
  const body = await response.json()
  return body.invoice ?? body
}

export async function getMonduInvoices(apiContext: APIRequestContext, orderUuid: string) {
  const response = await apiContext.get(`${API_URL}/orders/${orderUuid}/invoices`, {
    headers: headers(),
  })
  if (!response.ok()) {
    throw new Error(`Failed to list invoices for order ${orderUuid}: ${response.status()}`)
  }
  const body = await response.json()
  return body.invoices ?? body
}

export async function waitForMonduInvoice(
  apiContext: APIRequestContext,
  orderUuid: string,
  retries = 8,
  delayMs = 3000
) {
  for (let i = 0; i < retries; i++) {
    const invoices = await getMonduInvoices(apiContext, orderUuid)
    if (Array.isArray(invoices) && invoices.length > 0) return invoices[0]
    if (i < retries - 1) {
      await new Promise((resolve) => setTimeout(resolve, delayMs))
    }
  }
  throw new Error(`No invoice showed up at Mondu for order ${orderUuid}`)
}
