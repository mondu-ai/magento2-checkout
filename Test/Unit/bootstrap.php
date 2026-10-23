<?php

/**
 * Bootstrap for Mondu unit tests.
 *
 * Unit tests mock every Magento dependency, so they need Magento's code on the
 * autoloader but no installation. Point MAGENTO_ROOT at any Magento 2.4 checkout
 * with dev dependencies installed, or install the module inside one:
 *
 *   MAGENTO_ROOT=/path/to/magento php $MAGENTO_ROOT/vendor/bin/phpunit \
 *       --bootstrap Test/Unit/bootstrap.php Test/Unit
 */

declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);
$magentoRoot = getenv('MAGENTO_ROOT') ?: null;

if ($magentoRoot === null) {
    $dir = $moduleRoot;
    for ($i = 0; $i < 6; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/vendor/magento/framework/registration.php')) {
            $magentoRoot = $dir;
            break;
        }
    }
}

if ($magentoRoot === null || !file_exists($magentoRoot . '/vendor/autoload.php')) {
    throw new RuntimeException('Set MAGENTO_ROOT to a Magento 2.4 root with vendor/ installed.');
}

require_once $magentoRoot . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'Mondu\\Mondu\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $moduleRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
