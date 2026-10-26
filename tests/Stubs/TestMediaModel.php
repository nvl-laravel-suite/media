<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Media\Tests\Fixtures\Consumer\Contracts\HasMedia;
use Nvl\Media\Tests\Fixtures\Consumer\Traits\InteractsWithMedia;
use Nvl\Media\Traits\InteractsWithNvlMedia as NativeMediaRelations;

/**
 * Minimal test model for verifying InteractsWithMedia trait behavior.
 */
class TestMediaModel extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia, NativeMediaRelations {
        NativeMediaRelations::nvlMediaAssociations insteadof InteractsWithMedia;
        NativeMediaRelations::scopeHasNvlMedia insteadof InteractsWithMedia;
        InteractsWithMedia::registerNvlMedia insteadof NativeMediaRelations;
    }
    use SoftDeletes;

    protected $table = 'test_media_models';

    protected $fillable = ['name'];
}
