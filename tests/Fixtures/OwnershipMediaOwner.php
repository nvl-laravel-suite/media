<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Traits\InteractsWithNvlMedia;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Native host Media owner, independent from an NVL tenant or actor model. */
class OwnershipMediaOwner extends Model implements HasNvlMedia, Ownable
{
    use HasOwnership, HasUuids, InteractsWithNvlMedia;

    protected $table = 'media_test_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        $media->slot('documents')->oneToOne()->useDisk('public');
    }

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
