Review the pull request for the Mondu Magento 2 payment module (`Mondu_Mondu`):

$ARGUMENTS

## Review process

### 1. Gather changes

```bash
# If PR number is provided:
gh pr diff <number>
gh pr view <number>
gh pr checks <number>

# If reviewing current branch:
git diff main...HEAD
git log main...HEAD --oneline
```

### 2. PHPCS compliance

Verify the CI check passes (PSR-12 + Magento2 standards). If you can run it locally:
```bash
magento/vendor/bin/phpcs --standard=PSR12,Magento2 --ignore=magento/* .
```

Common violations to look for:
- Missing `declare(strict_types=1);`
- PHPDoc `@param`/`@return` type mismatches
- Line length > 120 characters
- Missing type hints

### 3. Magento 2 XML–PHP consistency

For every new or modified PHP class, verify the required XML registration exists:

| PHP change | Required XML |
|-----------|-------------|
| New observer class | `<observer>` in `etc/events.xml` or `etc/adminhtml/events.xml` (correct scope!) |
| New plugin class | `<plugin>` in `etc/di.xml` |
| New interface + implementation | `<preference>` in `etc/di.xml` |
| New payment method model | `<model>` in `etc/config.xml`, fields in `etc/adminhtml/system.xml`, code in `PaymentMethod::PAYMENTS` |
| New request handler | Constant + mapping in `Factory::$invokableClasses` |
| New frontend controller | Route in `etc/frontend/routes.xml` |
| New admin controller | Route in `etc/adminhtml/routes.xml`, `ADMIN_RESOURCE` constant, ACL in `etc/acl.xml` |
| New cron job | Schedule in `etc/crontab.xml` |
| Schema change | `etc/db_schema.xml` AND `etc/db_schema_whitelist.json` updated |
| New UI component | XML in `view/adminhtml/ui_component/`, DataProvider class, layout reference |
| New checkout component | Layout in `checkout_index_index.xml`, RequireJS module registration |

### 4. Configuration integrity

- Do `system.xml` `config_path` values match paths in `etc/config.xml` defaults?
- Are scope flags correct? (`showInDefault`/`showInWebsite`/`showInStore`)
  - API key, sandbox mode → website scope (`showInStore="0"`)
  - Payment enable/title/description → store scope (`showInStore="1"`)
  - Cron settings → website scope (`showInStore="0"`)
- Is there a reader method in `Model/Ui/ConfigProvider` for new config fields?
- Are `backend_model="Magento\Config\Model\Config\Backend\Encrypted"` used for sensitive fields?

### 5. Payment method completeness

If a change affects payment logic, verify:
- Does it apply to all 5 methods or just the relevant ones? (mondu, mondusepa, monduinstallment, monduinstallmentbyinvoice, mondupaynow)
- Are `Helpers/PaymentMethod::PAYMENTS` and `MAPPING` still accurate?
- Are `etc/config.xml` defaults consistent across methods?
- Does the frontend renderer in `view/frontend/web/js/view/payment/mondu.js` need updates?

### 6. Multistore safety

- Does new code use `ContextHelper::setConfigContextForOrder()` before reading store-scoped config?
- Do `Factory::create()` calls pass `$storeId`?
- For webhook changes — does multistore signature validation still work (primary lookup + store iteration fallback)?

### 7. Observer scope correctness

- Frontend events (`etc/events.xml`): `sales_order_place_before`, `sales_order_place_after`, `order_cancel_after`
- Admin events (`etc/adminhtml/events.xml`): `sales_order_shipment_save_after`, `sales_order_creditmemo_save_after`, `admin_system_config_changed_section_payment`
- New observers must extend `MonduObserver` with `$name` property set
- Observer name attribute must be unique and prefixed with `mondu_`

### 8. Security review

- **Webhook endpoint** — HMAC signature validated before processing? CSRF bypass still scoped to `/mondu/webhooks/index` only?
- **API key** — not logged, not in frontend config output, not in error payloads?
- **Admin controllers** — `ADMIN_RESOURCE` constant present? Extends `Backend\App\Action`?
- **User input** — validated/sanitized? Using `SearchCriteriaBuilder` for queries (no raw SQL)?
- **Templates** — `escapeHtml()` in `.phtml`, `text:` binding in Knockout (not `html:`)?
- **Serialization** — `SerializerInterface` used, never `unserialize()`?
- **CSP** — new external domains added to `etc/csp_whitelist.xml`?

### 9. Database changes

- `etc/db_schema.xml` modified → whitelist regenerated?
- Data migration needed for existing records → `Setup/Patch/Data/` patch created?
- New columns use correct types and attributes (`nullable`, `unsigned`, `default`, `comment`)?
- Indexes added for columns used in WHERE clauses?
- `sales_order` extension uses `resource="sales"` (not `default`)?

### 10. Translations

- New `__('...')` strings in PHP or `translate="label"` in XML?
- Added to all `i18n/*.csv` locale files? (15+ locales: de_DE, nl_NL, fr_FR, es_ES, etc.)

## Output format

Organize findings into:

### Must fix
Critical issues that block merging — broken XML registration, security gaps, missing config paths, scope errors.

### Should fix
Important improvements — missing PHPDoc, incomplete multistore support, missing translations, inconsistent payment method handling.

### Nice to have
Suggestions — code style improvements, refactoring opportunities, documentation updates.

### Deployment notes
List required Magento commands after merge:
- `setup:upgrade` — schema/patches
- `setup:di:compile` — DI/plugins/observers/preferences
- `cache:flush` — config/layout
- `setup:static-content:deploy` — JS/CSS (production only)