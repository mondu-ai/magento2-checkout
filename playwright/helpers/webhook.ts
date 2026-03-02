import * as crypto from 'crypto'
import { APIRequestContext } from '@playwright/test'

const MAGENTO_URL = process.env.MAGENTO_URL || ''
const WEBHOOK_URL = `${MAGENTO_URL.replace(/\/$/, '')}/mondu/webhooks/index`

function sign(payload: object, secret: string): string {
  return crypto
    .createHmac('sha256', secret)
    .update(JSON.stringify(payload))
    .digest('hex')
}

export async function sendWebhook(
  request: APIRequestContext,
  topic: string,
  payload: object,
  webhookSecret: string = process.env.WEBHOOK_SECRET || ''
) {
  const signature = sign(payload, webhookSecret)
  const response = await request.post(WEBHOOK_URL, {
    headers: {
      'Content-Type': 'application/json',
      'X-Mondu-Signature': signature,
      'X-Mondu-Topic': topic,
    },
    data: JSON.stringify(payload),
  })
  return response
}

// Flat structure matching what the PHP webhook handler reads:
// $params['order_uuid'], $params['external_reference_id'], $params['order_state']

export function buildOrderPendingPayload(orderUuid: string, externalRefId: string) {
  return {
    topic: 'order/pending',
    order_uuid: orderUuid,
    external_reference_id: externalRefId,
    order_state: 'pending',
  }
}

export function buildOrderConfirmedPayload(
  orderUuid: string,
  externalRefId: string,
  iban: string = 'DE89370400440532013000'
) {
  return {
    topic: 'order/confirmed',
    order_uuid: orderUuid,
    external_reference_id: externalRefId,
    order_state: 'confirmed',
    bank_account: {
      iban,
      bic: 'COBADEFFXXX',
      account_holder: 'Mondu GmbH',
    },
  }
}

export function buildOrderDeclinedPayload(
  orderUuid: string,
  externalRefId: string,
  reason: string = 'risk_policy'
) {
  return {
    topic: 'order/declined',
    order_uuid: orderUuid,
    external_reference_id: externalRefId,
    order_state: 'declined',
    reason,
  }
}
