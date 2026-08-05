<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Nvl\Media\Actions\BulkDeleteMediaAction;
use Nvl\Media\Actions\BulkMoveMediaAction;
use Nvl\Media\Actions\BulkTagMediaAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\RenameMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Models\Media;

/**
 * Test-only composition proving the focused mutation Actions work together.
 */
final readonly class MediaMutationHarness
{
    public function __construct(
        private UpdateMediaMetadataAction $update,
        private DeleteMediaAction $delete,
        private RenameMediaAction $rename,
        private ReplaceMediaFileAction $replace,
        private BulkDeleteMediaAction $bulkDelete,
        private BulkTagMediaAction $bulkTag,
        private BulkMoveMediaAction $bulkMove,
    ) {}

    /**
     * @param  UpdateMediaPayload|array<string, mixed>  $payload
     */
    public function update(string $id, UpdateMediaPayload|array $payload): Media
    {
        return $this->update->execute(
            $id,
            $payload instanceof UpdateMediaPayload
                ? $payload
                : UpdateMediaPayload::validateAndCreate($payload),
        );
    }

    public function delete(string $id): bool
    {
        return $this->delete->execute($id);
    }

    public function rename(string $id, string $filename): Media
    {
        return $this->rename->execute($id, $filename);
    }

    public function replace(string $id, UploadedFile $file): Media
    {
        return $this->replace->execute($id, $file);
    }

    /**
     * @param  list<string>  $ids
     */
    public function bulkDelete(array $ids): int
    {
        return $this->bulkDelete->execute($ids);
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $tags
     */
    public function bulkTag(array $ids, array $tags): int
    {
        return $this->bulkTag->execute($ids, $tags);
    }

    /**
     * @param  list<string>  $ids
     */
    public function bulkMove(array $ids, string $folder): int
    {
        return $this->bulkMove->execute($ids, $folder);
    }
}
