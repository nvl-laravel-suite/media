<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Nvl\Media\Models\Media;

class ConfiguredConsumerMedia extends Media
{
    protected static function booted(): void
    {
        static::creating(static function (self $media): void {
            $media->filename = 'configured:'.$media->filename;
        });
    }
}
