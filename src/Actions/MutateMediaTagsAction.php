<?php

declare(strict_types=1);

namespace Nvl\Media\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaMutationLock;

/**
 * Mutates canonical media tags under the shared media lock.
 */
final class MutateMediaTagsAction
{
    public function __construct(
        private readonly MediaMutationLock $mutationLock,
    ) {}

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function execute(
        Media|string $media,
        array $add = [],
        array $remove = [],
    ): Media {
        $mediaId = $media instanceof Media ? $media->id : $media;
        $add = $this->normalize($add);
        $remove = $this->normalize($remove);

        return $this->mutationLock->execute(
            $mediaId,
            function () use ($mediaId, $add, $remove): Media {
                return DB::transaction(function () use ($mediaId, $add, $remove): Media {
                    $locked = Media::query()->lockForUpdate()->findOrFail($mediaId);
                    $tags = array_values(array_diff($locked->tags ?? [], $remove));
                    $locked->tags = array_values(array_unique([...$tags, ...$add]));
                    $locked->save();

                    return $locked;
                });
            },
        );
    }

    /**
     * @param  array<int, string>  $tags
     * @return list<string>
     */
    private function normalize(array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $tag): string => trim($tag), $tags),
            static fn (string $tag): bool => $tag !== '',
        )));
    }
}
