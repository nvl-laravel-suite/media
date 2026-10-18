<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;

/** Drives actual host records and uploads through the public package APIs. */
final class OwnershipMediaScenario
{
    public static function owner(): OwnershipMediaOwner
    {
        return app(OwnershipWriter::class)->create(OwnershipMediaOwner::class, static fn (OwnershipAssignment $a): OwnershipMediaOwner => OwnershipMediaOwner::query()->forceCreate([...$a->attributes(), 'name' => 'Host owner']));
    }

    public static function upload(OwnershipMediaOwner $owner, bool $image = false, bool $deduplicateExisting = true): Media
    {
        $file = $image ? UploadedFile::fake()->image('source.png', 32, 32) : UploadedFile::fake()->createWithContent('secret.txt', 'private bytes');

        return app(UploadMediaAction::class)->execute($file, 'public', $owner, new MediaSlot('documents'), $file->getClientOriginalName(), deduplicateExisting: $deduplicateExisting, skipAutoVariations: true, uploadedBy: '1');
    }
}
