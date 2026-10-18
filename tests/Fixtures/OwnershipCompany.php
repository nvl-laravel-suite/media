<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Traits\InteractsWithNvlMedia;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model implements HasNvlMedia
{
    use InteractsWithNvlMedia;

    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('documents')->oneToOne()->useDisk('public');
    }

    protected $table = 'media_test_companies';

    protected $guarded = [];

    public $timestamps = false;
}
