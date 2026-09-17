<?php

declare(strict_types=1);

use Nvl\Media\Tests\MediaCatalogTenancyTestCase;
use Nvl\Media\Tests\MediaTenancyTestCase;
use Nvl\Media\Tests\MediaTestCase;

uses(MediaTestCase::class)->in('Feature', 'Integration', 'Unit');
uses(MediaTenancyTestCase::class)->in('Tenancy/Feature');
uses(MediaCatalogTenancyTestCase::class)->in('Tenancy/Catalog');
