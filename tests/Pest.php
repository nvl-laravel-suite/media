<?php

declare(strict_types=1);

use Nvl\Media\Tests\MediaTestCase;
use Nvl\Media\Tests\MediaUnitTestCase;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

uses(MediaTestCase::class)->in('Feature');

foreach (glob(__DIR__.'/Unit/*Test.php') ?: [] as $file) {
    uses(match (basename($file)) {
        'MediaMimeResolverTest.php', 'MimeTypeEnumTest.php' => PhpUnitTestCase::class,
        'GenerateImageVariationJobTest.php', 'MediaBuilderKeyTest.php', 'MediaImageVariationModelTest.php', 'MediaLifecycleServiceTest.php', 'MediaSourceResolverTest.php', 'MediaUrlResolverTest.php' => MediaTestCase::class,
        default => MediaUnitTestCase::class,
    })->in($file);
}
