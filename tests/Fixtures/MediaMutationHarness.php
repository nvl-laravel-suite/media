<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaUploader;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Test-only composition proving the focused mutation Actions work together.
 */
final readonly class MediaMutationHarness
{
    public function __construct(private MediaLibrary $update, private MediaLibrary $delete, private MediaLibrary $rename, private MediaUploader $replace, private MediaLibrary $bulkDelete, private MediaLibrary $bulkTag, private MediaLibrary $bulkMove) {}

    /**
     * @param  UpdateMediaPayload|array<string, mixed>  $payload
     */
    public function update(string $id, array $payload): Media
    {
        if (array_key_exists('is_public', $payload)) {
            $payload['isPublic'] = $payload['is_public'];
            unset($payload['is_public']);
        }
        if (isset($payload['translationMode']) && is_string($payload['translationMode'])) {
            $payload['translationMode'] = TranslationSyncMode::from($payload['translationMode']);
        }

        return $this->update->updateMetadata(auth()->user() ?? SystemActor::Background, $id, $payload);
    }

    public function delete(string $id): bool
    {
        return $this->delete->delete(auth()->user() ?? SystemActor::Background, $id);
    }

    public function rename(string $id, string $filename): Media
    {
        return $this->rename->rename(auth()->user() ?? SystemActor::Background, $id, $filename);
    }

    public function replace(string $id, UploadedFile $file): Media
    {
        return $this->replace->replace(auth()->user() ?? SystemActor::Background, $id, $file);
    }

    /**
     * @param  list<string>  $ids
     */
    public function bulkDelete(array $ids): int
    {
        return $this->bulkDelete->bulkDelete(auth()->user() ?? SystemActor::Background, $ids);
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $tags
     */
    public function bulkTag(array $ids, array $tags): int
    {
        return $this->bulkTag->bulkTag(auth()->user() ?? SystemActor::Background, $ids, $tags);
    }

    /**
     * @param  list<string>  $ids
     */
    public function bulkMove(array $ids, string $folder): int
    {
        return $this->bulkMove->move(auth()->user() ?? SystemActor::Background, $ids, $folder);
    }
}
