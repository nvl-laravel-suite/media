<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Tests\Fixtures\MediaTenancyConsumerServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

$root = getenv('NVL_TEST_SUITE_ROOT');
$root = is_string($root) && $root !== '' ? $root : dirname(__DIR__, 6);
require $root.'/vendor/autoload.php';
putenv('COMPOSER_VENDOR_DIR='.$root.'/vendor');

$app = Application::configure(basePath: __DIR__)
    ->withProviders([
        SupportServiceProvider::class,
        DataServiceProvider::class,
        FilterableServiceProvider::class,
        TenancyServiceProvider::class,
        MediaTenancyConsumerServiceProvider::class,
        TranslatableServiceProvider::class,
        MediaServiceProvider::class,
    ])
    ->withExceptions()
    ->withMiddleware()
    ->create();

exit($app->handleCommand(new ArgvInput));
