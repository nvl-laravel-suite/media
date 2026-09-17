<?php

declare(strict_types=1);

use Nvl\Media\Tests\MediaCatalogTenancyTestCase;
use Nvl\Media\Tests\MediaPlatformTenancyTestCase;
use Nvl\Media\Tests\MediaTenancyTestCase;
use Nvl\Media\Tests\MediaTenantAssetTestCase;
use Nvl\Media\Tests\MediaTestCase;

uses(MediaTestCase::class)->in('Feature', 'Unit');
uses(MediaTenancyTestCase::class)->in(
    'Tenancy/Feature/MediaTenancySchemaTest.php',
    'Tenancy/Feature/MediaTenantBoundaryTest.php',
    'Tenancy/Feature/MediaTenantQueuePayloadTest.php',
    'Tenancy/Feature/MediaTenantStorageTest.php',
    'Tenancy/Feature/MediaTenantMultipartTest.php',
);
uses(MediaTenantAssetTestCase::class)->in('Tenancy/Feature/MediaTenantAssetRoutesTest.php');
uses(MediaCatalogTenancyTestCase::class)->in('Tenancy/Catalog');
uses(MediaCatalogTenancyTestCase::class)->in('Tenancy/Integration/MediaTenantImportConcurrencyTest.php');
uses(MediaTenancyTestCase::class)->in('Tenancy/Integration/MediaTenantOwnerSlotDatabaseConcurrencyTest.php');
uses(MediaPlatformTenancyTestCase::class)->in('Tenancy/Feature/MediaPlatformAdoptionTest.php');
uses(MediaTenancyTestCase::class)->in('Integration/ProductionStackTest.php');
