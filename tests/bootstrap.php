<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

// Plugin lives at custom/plugins/ActPrimaryOrderRefs/; project root is four levels up.
$projectRootAutoloader = __DIR__ . '/../../../../vendor/autoload.php';

if (!file_exists($projectRootAutoloader)) {
    fwrite(STDERR, "Project-root autoloader not found at {$projectRootAutoloader}.\n");
    fwrite(STDERR, "Run `composer install` (with --dev) in the project root.\n");
    exit(1);
}

require $projectRootAutoloader;

// Force the test environment. The project's .env.local pins APP_ENV=dev, but the
// integration kernel needs APP_ENV=test to expose test.service_container.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

// This Shopware installation boots Shopware\Core\Kernel (see bin/console); the stale
// KERNEL_CLASS=App\Kernel in .env.test does not exist. Pin the correct kernel class.
$_SERVER['KERNEL_CLASS'] = $_ENV['KERNEL_CLASS'] = \Shopware\Core\Kernel::class;
putenv('KERNEL_CLASS=' . \Shopware\Core\Kernel::class);

// The DDEV `db` user only has DDL privileges on databases matching `test` / `test_%`
// (see `SHOW GRANTS`), not on the default `db_test` that Shopware would otherwise derive
// from DATABASE_URL. Pin the test database into the granted namespace so the test kernel
// can create/drop it. The host/credentials match the DDEV `db` service.
$testDatabaseUrl = getenv('TEST_DATABASE_URL')
    ?: 'mysql://db:db@db:3306/test_primary_order_refs';

$classLoader = (new TestBootstrapper())
    ->addActivePlugins('ActPrimaryOrderRefs')
    ->setDatabaseUrl($testDatabaseUrl)
    ->bootstrap()
    ->getClassLoader();

// Register the plugin's test namespace so PHPUnit can resolve the test classes.
$classLoader->addPsr4('Actualize\\ActPrimaryOrderRefs\\Tests\\', __DIR__ . '/');
