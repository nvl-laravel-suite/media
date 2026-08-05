<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Traits\InteractsWithMedia;

/**
 * Minimal test model for verifying InteractsWithMedia trait behavior.
 */
class TestMediaModel extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $table = 'test_media_models';

    protected $fillable = ['name'];
}
