Implement the following task in the Mondu Magento 2 payment module (`Mondu_Mondu`, namespace `Mondu\Mondu`):

$ARGUMENTS

## Before writing any code

1. **Understand the task scope** — determine which Magento areas are affected:
   - Model/Request handlers (API integration)
   - Model/Payment methods
   - Observer (event-driven logic)
   - Plugin (intercepting Magento core)
   - Controller (frontend routes or admin actions)
   - Helpers (business logic)
   - Configuration (admin settings)
   - Database schema
   - Frontend (JS/Knockout.js/layout XML)
   - Cron

2. **Read existing code** — before modifying any file, read it first. Key files to understand context:
   - `Model/Request/Factory.php` — all API handler constants and class mapping
   - `etc/di.xml` — DI preferences, plugins, virtualTypes, argument injection
   - `etc/events.xml` + `etc/adminhtml/events.xml` — event→observer bindings
   - `etc/config.xml` — default config values for all 5 payment methods
   - `Helpers/PaymentMethod.php` — `PAYMENTS` array and `MAPPING`
   - `Model/Ui/ConfigProvider.php` — config reading and frontend config provider

3. **Plan the implementation** — identify all files that need changes. In Magento 2, a single feature typically requires coordinated changes across PHP classes AND XML configuration.

## Implementation rules

### PHP
- Every file must have `declare(strict_types=1);`
- Use constructor dependency injection — never `ObjectManager::getInstance()` (except in Factory)
- Follow PSR-12 + Magento2 PHPCS standards
- Full PHPDoc on all public methods: `@param`, `@return`, `@throws`
- Use Magento interfaces over concrete classes in type hints (e.g., `OrderInterface` not `Order`)

### Observers
- Extend `Mondu\Mondu\Observer\MonduObserver`
- Set `protected string $name = 'ObserverName';`
- Implement `_execute(Observer $observer): void`
- Register in correct scope: `etc/events.xml` (frontend) or `etc/adminhtml/events.xml` (admin)
- Observer name attribute: prefix with `mondu_`

### Request Handlers (API calls)
- Create class in `Model/Request/` extending `CommonRequest`, implementing `RequestInterface`
- Add constant + class mapping in `Factory::$invokableClasses`
- Use `sendRequestWithParams($method, $url, $params)` for HTTP calls
- URL from `ConfigProvider::getApiUrl()`

### Payment Methods
- Class in `Model/Payment/` extending `AbstractMethod` with unique `CODE`
- Update `Helpers/PaymentMethod::PAYMENTS` and `MAPPING`
- Add defaults in `etc/config.xml` under `<default><payment><code>`
- Add admin fields in `etc/adminhtml/system.xml` with `config_path`
- Register renderer in `view/frontend/web/js/view/payment/mondu.js`

### Controllers
- Frontend: implement `ActionInterface`, route via `etc/frontend/routes.xml`
- Admin: extend `Backend\App\Action`, declare `ADMIN_RESOURCE` constant, route via `etc/adminhtml/routes.xml`

### Database
- Modify `etc/db_schema.xml` for schema changes
- Regenerate whitelist: `php bin/magento setup:db-declaration:generate-whitelist --module-name=Mondu_Mondu`
- Data migrations go in `Setup/Patch/Data/` implementing `DataPatchInterface`
- Use `$setup->getTable('table_name')` for table prefix support

### Configuration
- Default value in `etc/config.xml`
- Admin UI field in `etc/adminhtml/system.xml` with correct `config_path` and scope flags (`showInDefault`, `showInWebsite`, `showInStore`)
- Reader method in `Model/Ui/ConfigProvider`

### Plugins
- Class in `Plugin/` mirroring target class namespace
- Declare in `etc/di.xml` via `<plugin name="mondu_..." type="..."/>`
- Prefer `after` over `around` unless blocking execution is required

### Multistore
- Use `ContextHelper::setConfigContextForOrder()` for store-scoped config
- Pass `$storeId` to `Factory::create($method, $storeId)`

### Translations
- Add new user-facing strings to all `i18n/*.csv` files

## After implementation

1. Run PHPCS to verify coding standards:
   ```
   magento/vendor/bin/phpcs --standard=PSR12,Magento2 --ignore=magento/* .
   ```

2. List the Magento commands needed for deployment:
   - `php bin/magento setup:upgrade` — if schema/patches changed
   - `php bin/magento setup:di:compile` — if DI/plugins/observers changed
   - `php bin/magento cache:flush` — if config/layout changed
   - `php bin/magento setup:static-content:deploy` — if JS/CSS changed (production)