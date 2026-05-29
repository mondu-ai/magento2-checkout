import { test, expect, Page } from '@playwright/test';
import * as crypto from 'crypto';
import { execSync } from 'child_process';

const BASE = 'https://m2-ivan-local.casa-kuhl.de';
const CUSTOMER_EMAIL = 'roni_cost@example.com';
const CUSTOMER_PASSWORD = 'Test1234!';
const WEBHOOK_SECRET = 'Cg2O2mYlPj3DlOdPsU72';

function hmacSha256(body: string, secret: string): string {
  return crypto.createHmac('sha256', secret).update(body).digest('hex');
}

function dockerExec(cmd: string): string {
  return execSync(
    `docker exec shop-m2 bash -c '${cmd.replace(/'/g, "'\\''")}'`,
    { encoding: 'utf-8', timeout: 30_000 },
  ).trim();
}

function resetBuyerAttributes(): void {
  dockerExec(
    `cd /var/www/html && php -r "require 'app/bootstrap.php'; \\$b = \\Magento\\Framework\\App\\Bootstrap::create(BP, \\$_SERVER); \\$o = \\$b->getObjectManager(); \\$r = \\$o->get(\\Magento\\Customer\\Api\\CustomerRepositoryInterface::class); \\$c = \\$r->get('${CUSTOMER_EMAIL}'); \\$c->setCustomAttribute('mondu_buyer_uuid', null); \\$c->setCustomAttribute('mondu_buyer_state', null); \\$r->save(\\$c); echo 'ok';"`,
  );
}

async function customerLogin(page: Page): Promise<void> {
  await page.goto(`${BASE}/customer/account/login`);
  await page.locator('#email').waitFor({ state: 'visible', timeout: 15_000 });
  await page.locator('#email').fill(CUSTOMER_EMAIL);
  await page.locator('#password').fill(CUSTOMER_PASSWORD);
  await page.locator('#send2').click();
  await page.waitForLoadState('networkidle', { timeout: 30_000 });
  await expect(page).toHaveURL(/customer\/account/, { timeout: 15_000 });
}

async function sendBuyerWebhook(
  request: any,
  topic: string,
  uuid = 'test-buyer-uuid-001',
): Promise<void> {
  const body = JSON.stringify({
    topic,
    external_reference_id: CUSTOMER_EMAIL,
    uuid,
  });
  const signature = hmacSha256(body, WEBHOOK_SECRET);
  const resp = await request.post(`${BASE}/mondu/webhooks/index`, {
    headers: {
      'Content-Type': 'application/json',
      'X-Mondu-Signature': signature,
    },
    data: body,
  });
  expect(resp.status()).toBe(200);
  const json = await resp.json();
  expect(json.error).toBe(0);
}

test.describe('Buyer Onboarding – PT-3937', () => {

  test.describe('page & navigation', () => {

    test.beforeAll(() => {
      resetBuyerAttributes();
    });

    test('status page accessible for logged-in customer', async ({ page }) => {
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/status`);
      await expect(page.locator('.mondu-trade-account')).toBeVisible();
    });

    test('navigation link visible in account sidebar', async ({ page }) => {
      await customerLogin(page);
      await page.goto(`${BASE}/customer/account/`);
      await page.waitForLoadState('networkidle');
      const link = page.locator('a[href*="mondu/buyer_onboarding/status"]');
      await expect(link).toBeVisible();
      await expect(link).toContainText('Mondu Trade Account');
    });

    test('"Start Onboarding" button shown when no buyer state', async ({ page }) => {
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/status`);
      const btn = page.locator('.mondu-trade-account a.action.primary');
      await expect(btn).toBeVisible();
      await expect(btn).toContainText('Start Onboarding');
      const href = await btn.getAttribute('href');
      expect(href).toContain('mondu/buyer_onboarding/initiate');
    });

    test('status page redirects to login for guest', async ({ page }) => {
      await page.goto(`${BASE}/mondu/buyer_onboarding/status`);
      await expect(page).toHaveURL(/customer\/account\/login/);
    });

    test('initiate redirects to login for guest', async ({ page }) => {
      await page.goto(`${BASE}/mondu/buyer_onboarding/initiate`);
      await expect(page).toHaveURL(/customer\/account\/login/);
    });
  });

  test.describe('callback controllers', () => {

    test('success callback redirects to account', async ({ page }) => {
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/success`);
      await expect(page).toHaveURL(/customer\/account/);
    });

    test('cancel callback redirects to account', async ({ page }) => {
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/cancel`);
      await expect(page).toHaveURL(/customer\/account/);
    });

    test('decline callback redirects to account', async ({ page }) => {
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/decline`);
      await expect(page).toHaveURL(/customer\/account/);
    });
  });

  test.describe('webhooks & status display', () => {

    test('buyer/accepted webhook → status shows active', async ({ page, request }) => {
      await sendBuyerWebhook(request, 'buyer/accepted');
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/status`);
      const msg = page.locator('.mondu-trade-account .message-success');
      await expect(msg).toBeVisible();
      await expect(msg).toContainText('active');
    });

    test('buyer/declined webhook → status shows declined', async ({ page, request }) => {
      await sendBuyerWebhook(request, 'buyer/declined');
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/status`);
      const msg = page.locator('.mondu-trade-account .message-error');
      await expect(msg).toBeVisible();
      await expect(msg).toContainText('declined');
    });

    test('buyer/pending webhook → status shows pending', async ({ page, request }) => {
      await sendBuyerWebhook(request, 'buyer/pending');
      await customerLogin(page);
      await page.goto(`${BASE}/mondu/buyer_onboarding/status`);
      const msg = page.locator('.mondu-trade-account .message-info');
      await expect(msg).toBeVisible();
      await expect(msg).toContainText('reviewed');
    });
  });

  test.describe('webhook edge cases', () => {

    test('invalid signature returns 200 (ignored)', async ({ request }) => {
      const body = JSON.stringify({
        topic: 'buyer/accepted',
        external_reference_id: CUSTOMER_EMAIL,
        uuid: 'test-uuid-invalid',
      });
      const resp = await request.post(`${BASE}/mondu/webhooks/index`, {
        headers: {
          'Content-Type': 'application/json',
          'X-Mondu-Signature': 'invalidsignature',
        },
        data: body,
      });
      expect(resp.status()).toBe(200);
      const json = await resp.json();
      expect(json.error).toBe(0);
    });

    test('unknown customer returns 200', async ({ request }) => {
      const body = JSON.stringify({
        topic: 'buyer/accepted',
        external_reference_id: 'nonexistent@example.com',
        uuid: 'test-uuid-unknown',
      });
      const signature = hmacSha256(body, WEBHOOK_SECRET);
      const resp = await request.post(`${BASE}/mondu/webhooks/index`, {
        headers: {
          'Content-Type': 'application/json',
          'X-Mondu-Signature': signature,
        },
        data: body,
      });
      expect(resp.status()).toBe(200);
    });
  });
});
