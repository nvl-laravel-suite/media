<?php

declare(strict_types=1);

namespace Nvl\Media\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Media\Tests\Fixtures\ConfiguredConsumerMedia;

abstract class ConfiguredMediaTestCase extends MediaTestCase
{
    private array $originalMorphMap = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        Relation::morphMap($this->originalMorphMap, false);
    }

    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-media.models.media', ConfiguredConsumerMedia::class);
    }
}
