<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\Consumer\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media as MediaModel;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Tests\Fixtures\Consumer\Contracts\MediaLibraryContract;
use Nvl\Media\Tests\Fixtures\Consumer\Contracts\MediaUploadBuilder;
use Nvl\Media\Tests\Fixtures\Consumer\MediaLibrary;
use Nvl\Media\Tests\Fixtures\Data\Display\MediaUsage;
use Nvl\Support\Enums\SystemActor;

/**
 * Laravel facade for the replaceable MediaLibraryContract.
 *
 * @method static MediaUploadBuilder add(Model&HasNvlMedia $owner, UploadedFile|string $file, ?string $slot = null)
 * @method static MediaUploadBuilder copy(Model&HasNvlMedia $owner, UploadedFile|string $file)
 * @method static MediaUploadBuilder fromRequest(Model&HasNvlMedia $owner, string $key)
 * @method static MediaUploadBuilder fromUrl(Model&HasNvlMedia $owner, string $url, string ...$allowedMimeTypes)
 * @method static MediaUploadBuilder fromBase64(Model&HasNvlMedia $owner, string $payload, string ...$allowedMimeTypes)
 * @method static MediaUploadBuilder fromString(Model&HasNvlMedia $owner, string $contents)
 * @method static MediaUploadBuilder fromBinary(Model&HasNvlMedia $owner, string $contents, string $filename, string ...$allowedMimeTypes)
 * @method static MediaUploadBuilder fromDisk(Model&HasNvlMedia $owner, string $key, ?string $disk = null)
 * @method static LengthAwarePaginator<int, MediaModel> paginate(array<string, mixed> $filters = [], ?Authenticatable $actor = null, bool $includeVariations = false)
 * @method static MediaModel findOrFail(string $id, bool $includeVariations = true)
 * @method static string|null urlIfExists(MediaModel|null $media, string $variation = '')
 * @method static Collection<int, MediaUsage> usages(string $id)
 * @method static MediaAssociation attach(MediaModel $media, Model&HasNvlMedia $owner, string $collection = 'default', ?string $locale = null, ?int $order = null, array<string, mixed> $metadata = [], bool $dispatchVariations = true)
 * @method static MediaModel reuse(MediaModel|string $media, Model&HasNvlMedia $owner, string $collection = 'default', ?string $locale = null, ?int $order = null, array<string, mixed> $metadata = [], bool $dispatchVariations = true)
 * @method static int detach(MediaModel|string $media, Model&HasNvlMedia $owner, ?string $collection = null)
 * @method static bool delete(MediaModel|string $media, bool $force = false)
 * @method static MediaModel replace(MediaModel|string $media, UploadedFile $file)
 * @method static MediaModel rename(MediaModel|string $media, string $filename)
 * @method static MediaModel relocate(MediaModel|string $media, string $disk, MediaVisibility $visibility, ?int $expectedRevision = null)
 * @method static MediaModel updateMetadata(MediaModel|string $media, array $data)
 * @method static MediaImageVariation|null generateVariation(MediaModel $media, ConversionDefinition $definition, ?int $expectedRevision = null)
 * @method static MediaModel finalizeScan(MediaModel|string $media, array $result)
 * @method static array<string, mixed> initiateMultipart(array $data, Authenticatable|SystemActor $actor)
 * @method static array<string, mixed> signMultipartPart(array $part, Authenticatable|SystemActor $actor)
 * @method static MediaModel completeMultipart(array $completion, Authenticatable|SystemActor $actor)
 * @method static void abortMultipart(string $uploadId, Authenticatable|SystemActor $actor)
 * @method static bool allows(Authenticatable $actor, MediaAbility $ability, ?MediaModel $media = null, ?Model $owner = null)
 *
 * @see MediaLibrary
 *
 * @api
 */
final class Media extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaLibraryContract::class;
    }
}
