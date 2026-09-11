<?php

declare(strict_types=1);

/**
 * Bootstrap for Mondu integration tests.
 *
 * Finds the Magento root, initialises Magento's ObjectManager and makes it
 * available via \Magento\Framework\App\ObjectManager::getInstance().
 *
 * Run from the Magento root:
 *   vendor/bin/phpunit -c app/code/Mondu/Mondu/phpunit.xml
 *
 * Or from inside the module directory (Docker):
 *   /var/www/html/vendor/bin/phpunit -c phpunit.xml
 */

// Walk up from __DIR__ until we find the Magento bootstrap
$dir = __DIR__;
$magentoRoot = null;

for ($i = 0; $i < 12; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/app/bootstrap.php') && file_exists($dir . '/app/etc/env.php')) {
        $magentoRoot = $dir;
        break;
    }
}

if ($magentoRoot === null) {
    echo "ERROR: Could not locate Magento root (no app/bootstrap.php + app/etc/env.php found).\n";
    echo "Make sure the module is installed inside a Magento installation.\n";
    exit(1);
}

// Let Magento's own autoload.php define BP — suppress the harmless redefinition
// warning that can occur when phpunit also pre-loads Magento's autoloader.
require $magentoRoot . '/app/bootstrap.php';

// Initialise Magento and its ObjectManager (singleton).
// Tests retrieve it via \Magento\Framework\App\ObjectManager::getInstance().
$bootstrap = \Magento\Framework\App\Bootstrap::create($magentoRoot, $_SERVER);
$bootstrap->getObjectManager();