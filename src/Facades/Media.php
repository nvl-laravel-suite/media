<?php

declare(strict_types=1);

namespace Nvl\Media\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Data\Display\MediaUsage;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Data\MediaScanResultData;
use Nvl\Media\Data\Multipart\CompleteMultipartUploadData;
use Nvl\Media\Data\Multipart\InitiateMultipartUploadData;
use Nvl\Media\Data\Multipart\MultipartUploadSessionData;
use Nvl\Media\Data\Multipart\SignedMultipartPartData;
use Nvl\Media\Data\Multipart\SignMultipartPartData;
use Nvl\Media\Data\Mutations\UpdateMediaPayload;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\MediaAdder;
use Nvl\Media\MediaLibrary;
use Nvl\Media\Models\Media as MediaModel;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;

/**
 * Laravel facade for the replaceable MediaLibraryContract.
 *
 * @method static MediaAdder add(Model&HasMedia $owner, UploadedFile|string $file, ?string $slot = null)
 * @method static MediaAdder copy(Model&HasMedia $owner, UploadedFile|string $file)
 * @method static MediaAdder fromRequest(Model&HasMedia $owner, string $key)
 * @method static MediaAdder fromUrl(Model&HasMedia $owner, string $url, string ...$allowedMimeTypes)
 * @method static MediaAdder fromBase64(Model&HasMedia $owner, string $payload, string ...$allowedMimeTypes)
 * @method static MediaAdder fromString(Model&HasMedia $owner, string $contents)
 * @method static MediaAdder fromDisk(Model&HasMedia $owner, string $key, ?string $disk = null)
 * @method static LengthAwarePaginator<int, MediaModel> paginate(MediaFilter|array<string, mixed> $filters = [], ?Authenticatable $actor = null, bool $includeVariations = false)
 * @method static MediaModel findOrFail(string $id, bool $includeVariations = true)
 * @method static Collection<int, MediaUsage> usages(string $id)
 * @method static MediaAssociation attach(MediaModel $media, Model&HasMedia $owner, string $collection = 'default', ?string $locale = null, ?int $order = null, array<string, mixed> $metadata = [], bool $dispatchVariations = true)
 * @method static MediaModel reuse(MediaModel|string $media, Model&HasMedia $owner, string $collection = 'default', ?string $locale = null, ?int $order = null, array<string, mixed> $metadata = [], bool $dispatchVariations = true)
 * @method static int detach(MediaModel|string $media, Model&HasMedia $owner, ?string $collection = null)
 * @method static bool delete(MediaModel|string $media, bool $force = false)
 * @method static MediaModel replace(MediaModel|string $media, UploadedFile $file)
 * @method static MediaModel rename(MediaModel|string $media, string $filename)
 * @method static MediaModel updateMetadata(MediaModel|string $media, UpdateMediaPayload $data)
 * @method static MediaImageVariation|null generateVariation(MediaModel $media, ConversionDefinition $definition, ?int $expectedRevision = null)
 * @method static MediaModel finalizeScan(MediaModel|string $media, MediaScanResultData $result)
 * @method static MultipartUploadSessionData initiateMultipart(InitiateMultipartUploadData $data, MediaActorData $actor)
 * @method static SignedMultipartPartData signMultipartPart(SignMultipartPartData $part, MediaActorData $actor)
 * @method static MediaModel completeMultipart(CompleteMultipartUploadData $completion, MediaActorData $actor)
 * @method static void abortMultipart(string $uploadId, MediaActorData $actor)
 * @method static bool allows(Authenticatable $actor, MediaAbility $ability, ?MediaModel $media = null, ?Model $owner = null)
 *
 * @see MediaLibrary
 */
final class Media extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaLibraryContract::class;
    }
}
