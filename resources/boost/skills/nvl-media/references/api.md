# Public API: nvl/media

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Media\Contracts\HasNvlMedia`
- `Nvl\Media\Contracts\MediaAuthorization`
- `Nvl\Media\Contracts\MediaBatchAuthorization`
- `Nvl\Media\Contracts\MediaCatalogAccess`
- `Nvl\Media\Contracts\MediaCatalogImport`
- `Nvl\Media\Contracts\MediaContentScanner`
- `Nvl\Media\Contracts\MediaHostResolver`
- `Nvl\Media\Contracts\MediaSearchDriver`
- `Nvl\Media\Contracts\MultipartUploadGateway`
- `Nvl\Media\Contracts\RecoverableMultipartUploadGateway`
- `Nvl\Media\Conversions\ConversionDefinition`
- `Nvl\Media\Database\Factories\MediaAssociationFactory`
- `Nvl\Media\Database\Factories\MediaFactory`
- `Nvl\Media\Database\Factories\MediaImageVariationFactory`
- `Nvl\Media\Database\Factories\MediaTranslationFactory`
- `Nvl\Media\Enums\ImageCompression`
- `Nvl\Media\Enums\ImageFit`
- `Nvl\Media\Enums\ImageFormat`
- `Nvl\Media\Enums\ImagePreset`
- `Nvl\Media\Enums\MediaAbility`
- `Nvl\Media\Enums\MediaDeliveryMode`
- `Nvl\Media\Enums\MediaLifecycleStatus`
- `Nvl\Media\Enums\MediaResponseCode`
- `Nvl\Media\Enums\MediaType`
- `Nvl\Media\Enums\MediaVisibility`
- `Nvl\Media\Enums\MimeType`
- `Nvl\Media\Events\MediaAttached`
- `Nvl\Media\Events\MediaCatalogGrantAudited`
- `Nvl\Media\Events\MediaDetached`
- `Nvl\Media\Events\MediaMutated`
- `Nvl\Media\Events\MediaUploaded`
- `Nvl\Media\Exceptions\ConversionFailedException`
- `Nvl\Media\Exceptions\DiskNotDefinedException`
- `Nvl\Media\Exceptions\FileUnacceptableForCollection`
- `Nvl\Media\Exceptions\MediaBatchReadException`
- `Nvl\Media\Exceptions\MediaDependencyUnavailableException`
- `Nvl\Media\Exceptions\MediaException`
- `Nvl\Media\Exceptions\MediaInUseException`
- `Nvl\Media\Exceptions\MediaStorageException`
- `Nvl\Media\Exceptions\MediaUploadException`
- `Nvl\Media\MediaRegistration`
- `Nvl\Media\Models\Media`
- `Nvl\Media\Models\MediaAssociation`
- `Nvl\Media\Models\MediaImageVariation`
- `Nvl\Media\Models\MediaOwnerGrant`
- `Nvl\Media\Models\MediaTranslation`
- `Nvl\Media\Ownership\MediaOwnerCatalog`
- `Nvl\Media\Services\MediaAssetService`
- `Nvl\Media\Services\MediaAttachments`
- `Nvl\Media\Services\MediaDeliveryPolicy`
- `Nvl\Media\Services\MediaImport`
- `Nvl\Media\Services\MediaLibrary`
- `Nvl\Media\Services\MediaModelRegistry`
- `Nvl\Media\Services\MediaMultipart`
- `Nvl\Media\Services\MediaOwnerBatchReader`
- `Nvl\Media\Services\MediaReader`
- `Nvl\Media\Services\MediaScan`
- `Nvl\Media\Services\MediaUploader`
- `Nvl\Media\Services\MediaUrlResolver`
- `Nvl\Media\Services\MediaVariations`
- `Nvl\Media\Services\OwnerMediaReader`
- `Nvl\Media\Slots\MediaSlot`
- `Nvl\Media\Traits\InteractsWithNvlMedia`

## Nvl\Media\Contracts\HasNvlMedia

```text
interface Nvl\Media\Contracts\HasNvlMedia
public abstract function registerNvlMedia(Nvl\Media\MediaRegistration $media, ?Nvl\Media\Models\Media $asset=null): void
```

## Nvl\Media\Contracts\MediaAuthorization

```text
interface Nvl\Media\Contracts\MediaAuthorization
public abstract function allows(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Enums\MediaAbility $ability, ?Nvl\Media\Models\Media $media=null, ?Illuminate\Database\Eloquent\Model $owner=null): bool
```

## Nvl\Media\Contracts\MediaBatchAuthorization

```text
interface Nvl\Media\Contracts\MediaBatchAuthorization
public abstract function authorizeOwners(Nvl\Support\Owners\OwnerBatch $batch, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
public abstract function scopeAssociations(Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $batch, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
@param Builder<MediaAssociation> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaAssociation> $query
public abstract function scopeHostAssociations(Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $ownerPrototype, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $slot): void
@param Builder<MediaAssociation> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaAssociation> $query
public abstract function scopeMedia(Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $batch, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
@param Builder<Media> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
```

## Nvl\Media\Contracts\MediaCatalogAccess

```text
interface Nvl\Media\Contracts\MediaCatalogAccess
public abstract function assertCanGrant(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceId, int $revision): void
public abstract function assertCanRevoke(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId): void
```

## Nvl\Media\Contracts\MediaCatalogImport

```text
interface Nvl\Media\Contracts\MediaCatalogImport
public abstract function discard(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $file): void
@param array{operationId: string, disk: string, storagePath: string, digest: string, size: int, ownerScopeKey: string} $file
public abstract function inspect(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId): array
@return array{grantId: string, grantRevision: int, sourceId: string, sourceRevision: int, sourceDigest: string, disk: string, storagePath: string, filename: string, extension: string, mimeType: string, size: int, metadata: array<string, mixed>, tags: list<string>, translations: array<string, array<string, (string | null)>>}
public abstract function persist(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $source, array $file, string $idempotencyKey): Nvl\Media\Models\Media
@param array{grantId: string, grantRevision: int, sourceId: string, sourceRevision: int, sourceDigest: string, disk: string, storagePath: string, filename: string, extension: string, mimeType: string, size: int, metadata: array<string, mixed>, tags: list<string>, translations: array<string, array<string, (string | null)>>} $source
@param array{operationId: string, disk: string, storagePath: string, digest: string, size: int, ownerScopeKey: string} $file
public abstract function stage(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $source): array
@param array{grantId: string, grantRevision: int, sourceId: string, sourceRevision: int, sourceDigest: string, disk: string, storagePath: string, filename: string, extension: string, mimeType: string, size: int, metadata: array<string, mixed>, tags: list<string>, translations: array<string, array<string, (string | null)>>} $source
@return array{operationId: string, disk: string, storagePath: string, digest: string, size: int, ownerScopeKey: string}
```

## Nvl\Media\Contracts\MediaContentScanner

```text
interface Nvl\Media\Contracts\MediaContentScanner
public abstract function scan(Illuminate\Http\UploadedFile $file): void
```

## Nvl\Media\Contracts\MediaHostResolver

```text
interface Nvl\Media\Contracts\MediaHostResolver
public abstract function resolve(string $host): array
@return list<string>
```

## Nvl\Media\Contracts\MediaSearchDriver

```text
interface Nvl\Media\Contracts\MediaSearchDriver
public abstract function apply(Illuminate\Database\Eloquent\Builder $query, string $search): void
@param Builder<Media> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
```

## Nvl\Media\Contracts\MultipartUploadGateway

```text
interface Nvl\Media\Contracts\MultipartUploadGateway
public abstract function abort(array $session): void
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
public abstract function complete(array $session, array $completion): array
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
@param array{uploadId: string, parts: list<array{partNumber: int, etag: string, checksum?: (string | null)}>} $completion
@return array{path: string, checksum: string, size: int, mimeType: string, objectIdentity: (string | null)}
public abstract function initiate(array $session): void
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
public abstract function signPart(array $session, array $part): array
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
@param array{uploadId: string, partNumber: int, checksum: string, byteLength: int} $part
@return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: \DateTimeImmutable}
resolved @return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: DateTimeImmutable}
```

## Nvl\Media\Contracts\RecoverableMultipartUploadGateway

```text
interface Nvl\Media\Contracts\RecoverableMultipartUploadGateway extends Nvl\Media\Contracts\MultipartUploadGateway
public abstract function abort(array $session): void
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
public abstract function complete(array $session, array $completion): array
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
@param array{uploadId: string, parts: list<array{partNumber: int, etag: string, checksum?: (string | null)}>} $completion
@return array{path: string, checksum: string, size: int, mimeType: string, objectIdentity: (string | null)}
public abstract function initiate(array $session): void
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
public abstract function inspect(array $session): ?array
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
@return (array{path: string, checksum: string, size: int, mimeType: string, objectIdentity: (string | null)} | null)
public abstract function signPart(array $session, array $part): array
@param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
resolved @param array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int} $session
@param array{uploadId: string, partNumber: int, checksum: string, byteLength: int} $part
@return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: \DateTimeImmutable}
resolved @return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: DateTimeImmutable}
```

## Nvl\Media\Conversions\ConversionDefinition

```text
class Nvl\Media\Conversions\ConversionDefinition
public function __construct(string $name): mixed
@return void
public function background(string $color): static
public function blur(int $amount): static
public function brightness(int $amount): static
public function compression(Nvl\Media\Enums\ImageCompression|string $compression): static
public function contrast(int $amount): static
public function crop(int $width, int $height, string $position="center"): static
public static function cropTo(string $name, int $width, int $height, string $position="center", int $quality=85, ?string $format=null): self
@param string $name
@param int $width
@param int $height
@param string $position
@param int $quality
@param (string | null) $format
public function disabled(): static
public function enabled(bool $enabled=true): static
public function fit(Nvl\Media\Enums\ImageFit|string $method, int $width, int $height): static
public static function fitTo(string $name, string $method, int $width, int $height, int $quality=85, ?string $format=null): self
@param string $name
@param string $method
@param int $width
@param int $height
@param int $quality
@param (string | null) $format
public function flip(string $direction): static
public function format(Nvl\Media\Enums\ImageFormat|string $format): static
public static function formatOnly(string $name, string $format, int $quality=85): self
@param string $name
@param string $format
@param int $quality
public static function fromPayload(string $name, array $payload): self
@param array<string, mixed> $payload
public static function fromPreset(string $name, array $preset): self
@param string $name
@param array<string, mixed> $preset
public function getResultExtension(string $originalExtension): string
public function getResultMimeType(string $originalMimeType): string
public function greyscale(): static
public function height(int $height): static
public function keepOriginalImageFormat(): static
public function nonQueued(): static
public function onQueue(?string $queueName=null): static
public function orientation(int $degrees): static
public function performOnSlots(string ...$slots): static
@param string ...$slots
public function quality(int $quality): static
public function queued(): static
public static function resize(string $name, ?int $width=null, ?int $height=null, int $quality=85, ?string $format=null): self
@param string $name
@param (int | null) $width
@param (int | null) $height
@param int $quality
@param (string | null) $format
public function sharpen(int $amount): static
public function shouldBePerformedOn(string $slot): bool
public function toPayload(): array
@return array{_version: int, target_width: (int | null), target_height: (int | null), fit_method: (string | null), fit_width: (int | null), fit_height: (int | null), crop_width: (int | null), crop_height: (int | null), crop_position: (string | null), target_quality: (int | null), output_format: (string | null), sharpen_amount: (int | null), blur_amount: (int | null), apply_greyscale: bool, rotation_degrees: (int | null), flip_direction: (string | null), watermark_path: (string | null), watermark_position: (string | null), watermark_opacity: (int | null), background_color: (string | null), brightness_amount: (int | null), contrast_amount: (int | null), preserve_original_format: bool, compression: string, perform_on_slots: list<string>, should_be_queued: bool, queue_name: (string | null), enabled: bool}
public function validate(): void
@throws InvalidArgumentException
public function watermark(string $path, string $position="bottom-right", int $opacity=50): static
public function width(?int $width): static
public bool $applyGreyscale=false
public ?string $backgroundColor=null
public ?int $blurAmount=null
public ?int $brightnessAmount=null
public Nvl\Media\Enums\ImageCompression $compression=\Nvl\Media\Enums\ImageCompression::Lossy
public ?int $contrastAmount=null
public ?int $cropHeight=null
public string $cropPosition="center"
public ?int $cropWidth=null
public bool $enabled=true
public ?int $fitHeight=null
public ?string $fitMethod=null
public ?int $fitWidth=null
public ?string $flipDirection=null
public readonly string $name
public ?string $outputFormat=null
public array $performOnSlots=[]
@var list<string>
public bool $preserveOriginalFormat=false
public ?string $queueName=null
public ?int $rotationDegrees=null
public ?int $sharpenAmount=null
public bool $shouldBeQueued
public ?int $targetHeight=null
public ?int $targetQuality=null
public ?int $targetWidth=null
public int $watermarkOpacity=50
public ?string $watermarkPath=null
public string $watermarkPosition="bottom-right"
```

## Nvl\Media\Database\Factories\MediaAssociationFactory

```text
final class Nvl\Media\Database\Factories\MediaAssociationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MediaAssociation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Media\Models\MediaAssociation>
public function definition(): array
@return array<model-property<MediaAssociation>, mixed>
resolved @return array<model-property<Nvl\Media\Models\MediaAssociation>, mixed>
public function forMedia(Nvl\Media\Models\Media $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
```

## Nvl\Media\Database\Factories\MediaFactory

```text
class Nvl\Media\Database\Factories\MediaFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Media>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Media\Models\Media>
public function definition(): array
@return array<model-property<Media>, mixed>
resolved @return array<model-property<Nvl\Media\Models\Media>, mixed>
public function modelName(): string
```

## Nvl\Media\Database\Factories\MediaImageVariationFactory

```text
final class Nvl\Media\Database\Factories\MediaImageVariationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MediaImageVariation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Media\Models\MediaImageVariation>
public function definition(): array
@return array<model-property<MediaImageVariation>, mixed>
resolved @return array<model-property<Nvl\Media\Models\MediaImageVariation>, mixed>
public function forMedia(Nvl\Media\Models\Media $parent): static
```

## Nvl\Media\Database\Factories\MediaTranslationFactory

```text
final class Nvl\Media\Database\Factories\MediaTranslationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MediaTranslation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Media\Models\MediaTranslation>
public function definition(): array
@return array<model-property<MediaTranslation>, mixed>
resolved @return array<model-property<Nvl\Media\Models\MediaTranslation>, mixed>
public function forMedia(Nvl\Media\Models\Media $parent): static
```

## Nvl\Media\Enums\ImageCompression

```text
enum Nvl\Media\Enums\ImageCompression implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\ImageCompression Lossless=Nvl\Media\Enums\ImageCompression::Lossless="lossless"
public const Nvl\Media\Enums\ImageCompression Lossy=Nvl\Media\Enums\ImageCompression::Lossy="lossy"
public static function resolve(mixed $value): self
@throws InvalidArgumentException
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\ImageFit

```text
enum Nvl\Media\Enums\ImageFit implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\ImageFit Contain=Nvl\Media\Enums\ImageFit::Contain="contain"
public const Nvl\Media\Enums\ImageFit Crop=Nvl\Media\Enums\ImageFit::Crop="crop"
public const Nvl\Media\Enums\ImageFit Max=Nvl\Media\Enums\ImageFit::Max="max"
public const Nvl\Media\Enums\ImageFit Stretch=Nvl\Media\Enums\ImageFit::Stretch="stretch"
public static function resolve(mixed $value): self
@throws InvalidArgumentException
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\ImageFormat

```text
enum Nvl\Media\Enums\ImageFormat implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\ImageFormat Avif=Nvl\Media\Enums\ImageFormat::Avif="avif"
public const Nvl\Media\Enums\ImageFormat Gif=Nvl\Media\Enums\ImageFormat::Gif="gif"
public const Nvl\Media\Enums\ImageFormat Jpeg=Nvl\Media\Enums\ImageFormat::Jpeg="jpg"
public const Nvl\Media\Enums\ImageFormat Png=Nvl\Media\Enums\ImageFormat::Png="png"
public const Nvl\Media\Enums\ImageFormat Webp=Nvl\Media\Enums\ImageFormat::Webp="webp"
public function mimeType(): string
public static function resolve(mixed $value): self
@throws InvalidArgumentException
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\ImagePreset

```text
enum Nvl\Media\Enums\ImagePreset implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\ImagePreset Medium=Nvl\Media\Enums\ImagePreset::Medium="medium"
public const Nvl\Media\Enums\ImagePreset Small=Nvl\Media\Enums\ImagePreset::Small="small"
public const Nvl\Media\Enums\ImagePreset Thumbnail=Nvl\Media\Enums\ImagePreset::Thumbnail="thumb"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MediaAbility

```text
enum Nvl\Media\Enums\MediaAbility implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\MediaAbility Associate=Nvl\Media\Enums\MediaAbility::Associate="associate"
public const Nvl\Media\Enums\MediaAbility Delete=Nvl\Media\Enums\MediaAbility::Delete="delete"
public const Nvl\Media\Enums\MediaAbility Download=Nvl\Media\Enums\MediaAbility::Download="download"
public const Nvl\Media\Enums\MediaAbility List=Nvl\Media\Enums\MediaAbility::List="list"
public const Nvl\Media\Enums\MediaAbility ListAll=Nvl\Media\Enums\MediaAbility::ListAll="list_all"
public const Nvl\Media\Enums\MediaAbility ManageStaging=Nvl\Media\Enums\MediaAbility::ManageStaging="manage_staging"
public const Nvl\Media\Enums\MediaAbility Mutate=Nvl\Media\Enums\MediaAbility::Mutate="mutate"
public const Nvl\Media\Enums\MediaAbility Reuse=Nvl\Media\Enums\MediaAbility::Reuse="reuse"
public const Nvl\Media\Enums\MediaAbility Upload=Nvl\Media\Enums\MediaAbility::Upload="upload"
public const Nvl\Media\Enums\MediaAbility View=Nvl\Media\Enums\MediaAbility::View="view"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MediaDeliveryMode

```text
enum Nvl\Media\Enums\MediaDeliveryMode implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\MediaDeliveryMode Application=Nvl\Media\Enums\MediaDeliveryMode::Application="application"
public const Nvl\Media\Enums\MediaDeliveryMode Direct=Nvl\Media\Enums\MediaDeliveryMode::Direct="direct"
public const Nvl\Media\Enums\MediaDeliveryMode SignedApplication=Nvl\Media\Enums\MediaDeliveryMode::SignedApplication="signed_application"
public const Nvl\Media\Enums\MediaDeliveryMode TemporaryStorage=Nvl\Media\Enums\MediaDeliveryMode::TemporaryStorage="temporary_storage"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MediaLifecycleStatus

```text
enum Nvl\Media\Enums\MediaLifecycleStatus implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\MediaLifecycleStatus Available=Nvl\Media\Enums\MediaLifecycleStatus::Available="available"
public const Nvl\Media\Enums\MediaLifecycleStatus Deleted=Nvl\Media\Enums\MediaLifecycleStatus::Deleted="deleted"
public const Nvl\Media\Enums\MediaLifecycleStatus Failed=Nvl\Media\Enums\MediaLifecycleStatus::Failed="failed"
public const Nvl\Media\Enums\MediaLifecycleStatus PendingScan=Nvl\Media\Enums\MediaLifecycleStatus::PendingScan="pending_scan"
public const Nvl\Media\Enums\MediaLifecycleStatus PendingUpload=Nvl\Media\Enums\MediaLifecycleStatus::PendingUpload="pending_upload"
public const Nvl\Media\Enums\MediaLifecycleStatus ProcessingVariations=Nvl\Media\Enums\MediaLifecycleStatus::ProcessingVariations="processing_variations"
public const Nvl\Media\Enums\MediaLifecycleStatus Quarantined=Nvl\Media\Enums\MediaLifecycleStatus::Quarantined="quarantined"
public function isUsable(): bool
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MediaResponseCode

```text
enum Nvl\Media\Enums\MediaResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Media\Enums\MediaResponseCode BatchReadUnavailable=Nvl\Media\Enums\MediaResponseCode::BatchReadUnavailable="batch_read_unavailable"
public const Nvl\Media\Enums\MediaResponseCode ConversionFailed=Nvl\Media\Enums\MediaResponseCode::ConversionFailed="conversion_failed"
public const Nvl\Media\Enums\MediaResponseCode Deleted=Nvl\Media\Enums\MediaResponseCode::Deleted="deleted"
public const Nvl\Media\Enums\MediaResponseCode DiskNotDefined=Nvl\Media\Enums\MediaResponseCode::DiskNotDefined="disk_not_defined"
public const Nvl\Media\Enums\MediaResponseCode FileUnacceptableForCollection=Nvl\Media\Enums\MediaResponseCode::FileUnacceptableForCollection="file_unacceptable_for_collection"
public const Nvl\Media\Enums\MediaResponseCode MediaInUse=Nvl\Media\Enums\MediaResponseCode::MediaInUse="media_in_use"
public const Nvl\Media\Enums\MediaResponseCode MediaNotReusable=Nvl\Media\Enums\MediaResponseCode::MediaNotReusable="media_not_reusable"
public const Nvl\Media\Enums\MediaResponseCode MediaUploadFailed=Nvl\Media\Enums\MediaResponseCode::MediaUploadFailed="media_upload_failed"
public const Nvl\Media\Enums\MediaResponseCode OperationFailed=Nvl\Media\Enums\MediaResponseCode::OperationFailed="operation_failed"
public const Nvl\Media\Enums\MediaResponseCode S3DependencyUnavailable=Nvl\Media\Enums\MediaResponseCode::S3DependencyUnavailable="s3_dependency_unavailable"
public const Nvl\Media\Enums\MediaResponseCode StorageUnavailable=Nvl\Media\Enums\MediaResponseCode::StorageUnavailable="storage_unavailable"
public const Nvl\Media\Enums\MediaResponseCode Updated=Nvl\Media\Enums\MediaResponseCode::Updated="updated"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MediaType

```text
enum Nvl\Media\Enums\MediaType implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\MediaType ARCHIVE=Nvl\Media\Enums\MediaType::ARCHIVE="archive"
public const Nvl\Media\Enums\MediaType AUDIO=Nvl\Media\Enums\MediaType::AUDIO="audio"
public const Nvl\Media\Enums\MediaType CODE=Nvl\Media\Enums\MediaType::CODE="code"
public const Nvl\Media\Enums\MediaType DOCUMENT=Nvl\Media\Enums\MediaType::DOCUMENT="document"
public const Nvl\Media\Enums\MediaType IMAGE=Nvl\Media\Enums\MediaType::IMAGE="image"
public const Nvl\Media\Enums\MediaType OTHER=Nvl\Media\Enums\MediaType::OTHER="other"
public const Nvl\Media\Enums\MediaType VIDEO=Nvl\Media\Enums\MediaType::VIDEO="video"
public static function fromExtension(string $extension): self
public static function fromMimeType(string $mime_type): self
public function getLabel(): string
public function isVisual(): bool
public static function options(): array
@return array<int, array{label: string, value: string}>
public function supportsConversions(): bool
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MediaVisibility

```text
enum Nvl\Media\Enums\MediaVisibility implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\MediaVisibility Private=Nvl\Media\Enums\MediaVisibility::Private="private"
public const Nvl\Media\Enums\MediaVisibility Public=Nvl\Media\Enums\MediaVisibility::Public="public"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Enums\MimeType

```text
enum Nvl\Media\Enums\MimeType implements BackedEnum, UnitEnum: string
public const Nvl\Media\Enums\MimeType Aac=Nvl\Media\Enums\MimeType::Aac="audio/aac"
public const Nvl\Media\Enums\MimeType Avif=Nvl\Media\Enums\MimeType::Avif="image/avif"
public const Nvl\Media\Enums\MimeType Bmp=Nvl\Media\Enums\MimeType::Bmp="image/bmp"
public const Nvl\Media\Enums\MimeType Csv=Nvl\Media\Enums\MimeType::Csv="text/csv"
public const Nvl\Media\Enums\MimeType Flac=Nvl\Media\Enums\MimeType::Flac="audio/flac"
public const Nvl\Media\Enums\MimeType Gif=Nvl\Media\Enums\MimeType::Gif="image/gif"
public const Nvl\Media\Enums\MimeType Gz=Nvl\Media\Enums\MimeType::Gz="application/gzip"
public const Nvl\Media\Enums\MimeType Jpg=Nvl\Media\Enums\MimeType::Jpg="image/jpeg"
public const Nvl\Media\Enums\MimeType Json=Nvl\Media\Enums\MimeType::Json="application/json"
public const Nvl\Media\Enums\MimeType Mov=Nvl\Media\Enums\MimeType::Mov="video/quicktime"
public const Nvl\Media\Enums\MimeType Mp3=Nvl\Media\Enums\MimeType::Mp3="audio/mpeg"
public const Nvl\Media\Enums\MimeType Mp4=Nvl\Media\Enums\MimeType::Mp4="video/mp4"
public const Nvl\Media\Enums\MimeType Mpeg=Nvl\Media\Enums\MimeType::Mpeg="video/mpeg"
public const Nvl\Media\Enums\MimeType Ogg=Nvl\Media\Enums\MimeType::Ogg="audio/ogg"
public const Nvl\Media\Enums\MimeType Pdf=Nvl\Media\Enums\MimeType::Pdf="application/pdf"
public const Nvl\Media\Enums\MimeType Png=Nvl\Media\Enums\MimeType::Png="image/png"
public const Nvl\Media\Enums\MimeType Svg=Nvl\Media\Enums\MimeType::Svg="image/svg+xml"
public const Nvl\Media\Enums\MimeType Txt=Nvl\Media\Enums\MimeType::Txt="text/plain"
public const Nvl\Media\Enums\MimeType Wav=Nvl\Media\Enums\MimeType::Wav="audio/wav"
public const Nvl\Media\Enums\MimeType Webm=Nvl\Media\Enums\MimeType::Webm="video/webm"
public const Nvl\Media\Enums\MimeType Webp=Nvl\Media\Enums\MimeType::Webp="image/webp"
public const Nvl\Media\Enums\MimeType Xml=Nvl\Media\Enums\MimeType::Xml="application/xml"
public const Nvl\Media\Enums\MimeType Zip=Nvl\Media\Enums\MimeType::Zip="application/zip"
public static function archives(): array
@return array<int, self>
resolved @return array<int, Nvl\Media\Enums\MimeType>
public static function audio(): array
@return array<int, self>
resolved @return array<int, Nvl\Media\Enums\MimeType>
public static function documents(): array
@return array<int, self>
resolved @return array<int, Nvl\Media\Enums\MimeType>
public function extension(): string
public static function fromExtension(string $extension): ?self
@param string $extension
@return (self | null)
resolved @return (Nvl\Media\Enums\MimeType | null)
public static function fromMimeString(string $mimeString): ?self
@param string $mimeString
@return (self | null)
resolved @return (Nvl\Media\Enums\MimeType | null)
public static function images(): array
@return array<int, self>
resolved @return array<int, Nvl\Media\Enums\MimeType>
public function isAudio(): bool
public function isDocument(): bool
public function isImage(): bool
public function isLossyReEncode(self $target): bool
@param self $target
resolved @param Nvl\Media\Enums\MimeType $target
@return bool
public function isRasterOptimizable(): bool
public function isVideo(): bool
public static function rasterImages(): array
@return array<int, self>
resolved @return array<int, Nvl\Media\Enums\MimeType>
public function supportsConversion(): bool
public static function toStrings(array $types): array
@param array<int, self> $types
resolved @param array<int, Nvl\Media\Enums\MimeType> $types
@return array<int, string>
public static function videos(): array
@return array<int, self>
resolved @return array<int, Nvl\Media\Enums\MimeType>
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Media\Events\MediaAttached

```text
final readonly class Nvl\Media\Events\MediaAttached implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $mediaId, array $association, int $schemaVersion=1): mixed
@param string $mediaId
@param array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: (string | null)} $association
public static function fromAssociation(Nvl\Media\Models\MediaAssociation $association): self
@param MediaAssociation $association
resolved @param Nvl\Media\Models\MediaAssociation $association
@return self
resolved @return Nvl\Media\Events\MediaAttached
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $association
@param array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: (string | null)} $association
public readonly string $mediaId
@param string $mediaId
public readonly int $schemaVersion
```

## Nvl\Media\Events\MediaCatalogGrantAudited

```text
final readonly class Nvl\Media\Events\MediaCatalogGrantAudited implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $operation, string $grantId, string $ownerScopeKey, string $mediaId, int $sourceRevision, int $grantRevision, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly string $grantId
public readonly int $grantRevision
public readonly string $mediaId
public readonly string $operation
public readonly string $ownerScopeKey
public readonly int $schemaVersion
public readonly int $sourceRevision
```

## Nvl\Media\Events\MediaDetached

```text
final readonly class Nvl\Media\Events\MediaDetached implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $mediaId, array $affectedAssociations, int $schemaVersion=1): mixed
@param string $mediaId
@param array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: (string | null)}> $affectedAssociations
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $affectedAssociations
@param array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: (string | null)}> $affectedAssociations
public readonly string $mediaId
@param string $mediaId
public readonly int $schemaVersion
```

## Nvl\Media\Events\MediaMutated

```text
final readonly class Nvl\Media\Events\MediaMutated implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $mediaId, string $mutation, array $affectedAssociations=[], int $schemaVersion=1): mixed
@param string $mediaId
@param string $mutation
@param array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: (string | null)}> $affectedAssociations
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $affectedAssociations
@param array<int, array{media_id: string, associable_type: string, associable_id: string, collection: string, locale: (string | null)}> $affectedAssociations
public readonly string $mediaId
@param string $mediaId
public readonly string $mutation
@param string $mutation
public readonly int $schemaVersion
```

## Nvl\Media\Events\MediaUploaded

```text
final class Nvl\Media\Events\MediaUploaded implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $mediaId, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly string $mediaId
public readonly int $schemaVersion
```

## Nvl\Media\Exceptions\ConversionFailedException

```text
class Nvl\Media\Exceptions\ConversionFailedException extends Nvl\Media\Exceptions\MediaException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\DiskNotDefinedException

```text
class Nvl\Media\Exceptions\DiskNotDefinedException extends Nvl\Media\Exceptions\MediaException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\FileUnacceptableForCollection

```text
class Nvl\Media\Exceptions\FileUnacceptableForCollection extends Nvl\Media\Exceptions\MediaException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\MediaBatchReadException

```text
final class Nvl\Media\Exceptions\MediaBatchReadException extends RuntimeException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\MediaDependencyUnavailableException

```text
final class Nvl\Media\Exceptions\MediaDependencyUnavailableException extends Nvl\Media\Exceptions\MediaUploadException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\MediaException

```text
abstract class Nvl\Media\Exceptions\MediaException extends Exception implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\MediaInUseException

```text
final class Nvl\Media\Exceptions\MediaInUseException extends Nvl\Media\Exceptions\MediaException
public function package(): string
public static function privateVisibility(string $mediaId): self
public static function publicAsset(string $mediaId, int $associationCount): self
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\MediaStorageException

```text
final class Nvl\Media\Exceptions\MediaStorageException extends RuntimeException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\Exceptions\MediaUploadException

```text
class Nvl\Media\Exceptions\MediaUploadException extends Nvl\Media\Exceptions\MediaException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Media\MediaRegistration

```text
final class Nvl\Media\MediaRegistration
public function conversion(string $name): Nvl\Media\Conversions\ConversionDefinition
public function conversions(): array
@return array<string, ConversionDefinition>
resolved @return array<string, Nvl\Media\Conversions\ConversionDefinition>
public static function forOwner(Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, ?Nvl\Media\Models\Media $asset=null): self
public function getSlot(string $name): ?Nvl\Media\Slots\MediaSlot
public function slot(string $name): Nvl\Media\Slots\MediaSlot
public function slots(): Illuminate\Support\Collection
@return Collection<string, MediaSlot>
resolved @return Illuminate\Support\Collection<string, Nvl\Media\Slots\MediaSlot>
```

## Nvl\Media\Models\Media

```text
class Nvl\Media\Models\Media extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable, Nvl\Translatable\Contracts\TranslatableModel
public const string TABLE=\Nvl\Media\Definitions\Tables\MediaTables::Media
public static function allowsCentralCatalog(): bool
public function associations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<MediaAssociation, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Media\Models\MediaAssociation, $this>
public function buildPath(): string
public function buildPrivateUrl(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, DateTimeInterface $expiration, array $parameters=[]): string
@param array<string, (scalar | null)> $parameters
public function buildPublicUrl(array $parameters=[], ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
@param array<string, (scalar | null)> $parameters
public function buildUrl(array $parameters=[], ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
@param array<string, (scalar | null)> $parameters
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fileExistsOnDisk(): bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function getAvailableLocales(): array
@return list<string>
public function getConnectionName(): ?string
public function getCurrentLocale(): string
public function getPath(string $variation=""): string
public function getRelationValue(mixed $key): mixed
@param string $key
public function getTable(): string
public function getTemporaryUrl(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, DateTimeInterface $expiration, string $variation=""): string
public function getTranslatedAttributes(?string $locale=null): array
@return array<string, mixed>
public function getTranslation(?string $locale=null, bool $withFallback=true): ?Illuminate\Database\Eloquent\Model
public function getUrl(string $variation="", ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
public function getUsagesSummary(): Illuminate\Support\Collection
@return Collection<int, array{type: string, id: string, collection: string}>
resolved @return Illuminate\Support\Collection<int, array{type: string, id: string, collection: string}>
public function getVariation(string $label): ?Nvl\Media\Models\MediaImageVariation
public function getVariationPath(string $label): string
public function getVariationUrl(string $label): string
public function hasTag(string $tag): bool
public function hasTranslation(?string $locale=null): bool
public function hasVariation(string $label): bool
public function humanReadableSize(): string
public function imageVariations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<MediaImageVariation, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Media\Models\MediaImageVariation, $this>
public function isArchive(): bool
public function isAudio(): bool
public function isAvailable(): bool
public function isDocument(): bool
public function isImage(): bool
public function isTranslatableAttribute(string $key): bool
public function isUsed(): bool
public function isVideo(): bool
public function newEloquentBuilder(mixed $query): Nvl\Media\Builders\MediaBuilder
@param QueryBuilder $query
resolved @param Illuminate\Database\Query\Builder $query
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function resolveRouteBinding(mixed $value, mixed $field=null): ?Nvl\Media\Models\Media
@param mixed $value
@param (string | null) $field
public function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public static function rootFolder(?string $disk=null): string
@param (string | null) $disk
@return string
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopeAvailable(Illuminate\Database\Eloquent\Builder $query): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
public function scopeOfType(Illuminate\Database\Eloquent\Builder $query, Nvl\Media\Enums\MediaType $type): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
public function scopeOnDisk(Illuminate\Database\Eloquent\Builder $query, string $disk): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
public function scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction="asc", ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public function scopePrivate(Illuminate\Database\Eloquent\Builder $query): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
public function scopePublic(Illuminate\Database\Eloquent\Builder $query): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
public function scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value=\Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public function scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public function scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public function scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public function scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media>
public function scopeWithTag(Illuminate\Database\Eloquent\Builder $query, string $tag): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> $query
public function setIsPublicAttribute(bool $value): void
public function setLocale(string $locale): self
public function setVisibilityAttribute(Nvl\Media\Enums\MediaVisibility|string $visibility): void
public static function storagePath(string $folder, ?string $disk=null): string
@param string $folder
@param (string | null) $disk
@return string
public function translated(string $field, ?string $locale=null): mixed
public function translation(?string $locale=null): Illuminate\Database\Eloquent\Relations\HasOne
@return HasOne<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasOne<Illuminate\Database\Eloquent\Model, $this>
public function translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition
public function translationResourceKey(): int|string
public function translations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, $this>
public function uploader(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
@method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
@method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = TranslationComparisonValue::Omitted, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> whereTranslated(string $field, mixed $operator, mixed $value = Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale = null)
@method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> whereTranslationNotNull(string $field, ?string $locale = null)
@method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> whereTranslationNull(string $field, ?string $locale = null)
@method static Builder<static> withAllTranslations()
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> withAllTranslations()
@method static Builder<static> withResolvedTranslations(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\Media> withResolvedTranslations(?string $locale = null)
@property-read EloquentCollection<int, MediaAssociation> $associations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Media\Models\MediaAssociation> $associations
@property (Carbon | null) $available_at
resolved @property (Illuminate\Support\Carbon | null) $available_at
@property (string | null) $catalog_import_key
@property (string | null) $catalog_source_digest
@property (string | null) $catalog_source_id
@property (int | null) $catalog_source_revision
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property (Carbon | null) $deleted_at
resolved @property (Illuminate\Support\Carbon | null) $deleted_at
@property string $digest
@property string $disk
@property (int | null) $duration_ms
@property string $extension
@property (string | null) $failure_code
@property (array<string, mixed> | null) $failure_context
@property string $filename
@property (string | null) $folder
@property string $hash
@property (int | null) $height
@property string $id
@property-read EloquentCollection<int, MediaImageVariation> $imageVariations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Media\Models\MediaImageVariation> $imageVariations
@property bool $is_public
@property (array<string, mixed> | null) $metadata
@property string $mime_type
@property-read (string | null) $owner_reference
@property-read (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property (Carbon | null) $quarantined_at
resolved @property (Illuminate\Support\Carbon | null) $quarantined_at
@property int $revision
@property int $size
@property MediaLifecycleStatus $status
resolved @property Nvl\Media\Enums\MediaLifecycleStatus $status
@property (string | null) $storage_path
@property (array<int, string> | null) $tags
@property (string | null) $tenant_id
@property-read EloquentCollection<int, MediaTranslation> $translations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Media\Models\MediaTranslation> $translations
@property MediaType $type
resolved @property Nvl\Media\Enums\MediaType $type
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
@property (string | null) $upload_session_id
@property (string | null) $uploaded_by
@property (string | null) $uploaded_by_type
@property (array<string, array<string, mixed>> | null) $variation_definitions
@property MediaVisibility $visibility
resolved @property Nvl\Media\Enums\MediaVisibility $visibility
@property (int | null) $width
```

## Nvl\Media\Models\MediaAssociation

```text
class Nvl\Media\Models\MediaAssociation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Media\Definitions\Tables\MediaTables::Associations
public function associable(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function media(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Media, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Media\Models\Media, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaAssociation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopeForCollection(Illuminate\Database\Eloquent\Builder $query, string $collection): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaAssociation> $query
public function scopeForModel(Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $model): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaAssociation> $query
public function scopeOrdered(Illuminate\Database\Eloquent\Builder $query): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaAssociation> $query
@property string $associable_id
@property string $associable_type
@property string $collection
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $id
@property bool $is_active
@property (string | null) $locale
@property string $media_id
@property (array<string, mixed> | null) $metadata
@property int $order
@property-read (string | null) $owner_reference
@property-read (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property (Carbon | null) $replaced_at
resolved @property (Illuminate\Support\Carbon | null) $replaced_at
@property (string | null) $tenant_id
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Media\Models\MediaImageVariation

```text
class Nvl\Media\Models\MediaImageVariation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Media\Definitions\Tables\MediaTables::ImageVariations
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getFilename(): string
public function getMimeType(): string
public function getPath(): string
public function getTable(): string
public function getUrl(): string
public function media(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Media, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Media\Models\Media, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaImageVariation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property int $attempts
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property (array<string, mixed> | null) $failure_context
@property string $format
@property int $height
@property string $id
@property string $label
@property string $media_id
@property-read (string | null) $owner_reference
@property-read (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property int $quality
@property int $size
@property int $source_revision
@property string $status
@property (string | null) $storage_path
@property (string | null) $tenant_id
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
@property int $width
```

## Nvl\Media\Models\MediaOwnerGrant

```text
final class Nvl\Media\Models\MediaOwnerGrant extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Media\Definitions\Tables\MediaTables::OwnerGrants
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function media(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Media, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Media\Models\Media, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaOwnerGrant>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property bool $enabled
@property string $id
@property-read Media $media
resolved @property-read Nvl\Media\Models\Media $media
@property string $media_id
@property-read (string | null) $owner_scope_key
@property int $revision
@property (Carbon | null) $revoked_at
resolved @property (Illuminate\Support\Carbon | null) $revoked_at
@property int $source_revision
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Media\Models\MediaTranslation

```text
class Nvl\Media\Models\MediaTranslation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Media\Definitions\Tables\MediaTables::I18n
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function media(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Media, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Media\Models\Media, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaTranslation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopeForLocale(Illuminate\Database\Eloquent\Builder $query, string $locale): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Models\MediaTranslation> $query
@property (string | null) $alt
@property (string | null) $caption
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property (string | null) $description
@property string $id
@property string $locale
@property string $media_id
@property-read (string | null) $owner_reference
@property-read (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property (string | null) $tenant_id
@property (string | null) $title
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Media\Ownership\MediaOwnerCatalog

```text
final class Nvl\Media\Ownership\MediaOwnerCatalog implements Nvl\Media\Contracts\MediaCatalogImport
public function __construct(Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Ownership\Contracts\OwnershipContext $context, Nvl\Support\Ownership\Contracts\OwnershipReadiness $readiness, Nvl\Support\Ownership\Services\OwnershipResourceRegistry $resources, Nvl\Media\Contracts\MediaCatalogAccess $access, Nvl\Media\Services\MediaCatalogProjection $projection, Nvl\Media\Services\MediaFileOperator $files, Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaPathResolver $paths, Nvl\Media\Services\MediaFileEffectScheduler $effects, Nvl\Media\Services\MediaVariationDispatcher $variations, Nvl\Support\Ownership\Contracts\OwnershipWriter $writer, Nvl\Media\Ownership\MediaOwnershipConstraints $constraints, Nvl\Media\Services\MediaDeliveryPolicy $deliveryPolicy): mixed
public function discard(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $file): void
public function grant(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceId, int $sourceRevision): Nvl\Media\Models\MediaOwnerGrant
public function import(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId, int $expectedGrantRevision, int $expectedSourceRevision, string $idempotencyKey): Nvl\Media\Models\Media
public function inspect(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId): array
public function persist(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $source, array $file, string $idempotencyKey): Nvl\Media\Models\Media
public function revoke(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $grantId, int $expectedRevision): Nvl\Media\Models\MediaOwnerGrant
public function stage(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $source): array
```

## Nvl\Media\Services\MediaAssetService

```text
final class Nvl\Media\Services\MediaAssetService
public function __construct(Nvl\Media\Services\MediaFileExistence $existence, Nvl\Media\Ownership\MediaDeliveryGuard $delivery, Nvl\Media\Services\MediaDeliveryPolicy $policy): mixed
public function resolve(Nvl\Media\Models\Media $media, ?string $variationLabel=null, Nvl\Media\Enums\MediaDeliveryMode $mode=\Nvl\Media\Enums\MediaDeliveryMode::Application): array
@return array{disk: string, path: string, mime_type: string, etag: string}
```

## Nvl\Media\Services\MediaAttachments

```text
final class Nvl\Media\Services\MediaAttachments
public function __construct(Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Services\MediaVariationDispatcher $variationDispatcher, Nvl\Media\Services\MediaMutationLock $mutationLock, Nvl\Media\Ownership\MediaOwnershipParentResolver $owners, Nvl\Support\Ownership\Contracts\OwnershipBoundary $tenantBoundary, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Support\Ownership\Contracts\OwnershipWriter $ownershipWriter, Nvl\Media\Support\MediaBatchOwnerAdmission $removeOwners, Nvl\Media\Contracts\MediaAuthorization $authorization, Nvl\Media\Services\MediaMutationLock $lock, Nvl\Media\Services\MediaLibrary $deleteAction, Nvl\Media\Services\MediaOwnerSlotResolver $resolver, Nvl\Media\Services\MediaStagingPolicy $stagingPolicy, Nvl\Media\Services\MediaLibrary $deleteMedia, Nvl\Media\Services\MediaOwnerSlotIdempotency $idempotency, Nvl\Media\Services\MediaUploader $uploadMedia, Nvl\Media\Services\MediaLocalFileMaterializer $materializer): mixed
public function attach(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, Illuminate\Database\Eloquent\Model $model, string $collection="default", ?string $locale=null, ?int $order=null, array $metadata=[], bool $dispatchVariations=true, bool $requirePublic=false, ?Nvl\Media\Slots\MediaSlot $slot=null): Nvl\Media\Models\MediaAssociation
@param array<string, mixed> $metadata
public function clearCollection(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string $collection="default"): void
public function clearCollectionExcept(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string $collection="default", array|Illuminate\Support\Collection $except=[]): void
@param (array<int, (Media | string)> | Collection<int, (Media | string)>) $except
resolved @param (array<int, (Nvl\Media\Models\Media | string)> | Illuminate\Support\Collection<int, (Nvl\Media\Models\Media | string)>) $except
public function clearSlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot, ?string $idempotencyKey=null): void
public function copySlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot, string $sourceMediaId, ?string $idempotencyKey=null): Nvl\Media\Models\Media
public function deleteAll(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model): void
public function detach(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, Illuminate\Database\Eloquent\Model $model, ?string $collection=null): int
@param (Media | string) $media
resolved @param (Nvl\Media\Models\Media | string) $media
@param (string | null) $collection
@return int
public function getSlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot): ?Nvl\Media\Models\Media
public function remove(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $mediaId, ?string $collection=null): void
public function removeFromModel(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string|Nvl\Media\Models\Media $media, ?string $collection=null, ?Closure $beforeOrphanDelete=null): void
@param (Closure(Media): void | null) $beforeOrphanDelete
resolved @param (Closure(Nvl\Media\Models\Media): void | null) $beforeOrphanDelete
public function replaceSlot(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, string $slot, string $mediaId, ?string $idempotencyKey=null): Nvl\Media\Models\Media
public function reuse(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, string $collection="default", ?string $locale=null, ?int $order=null, array $metadata=[], bool $dispatchVariations=true): Nvl\Media\Models\MediaAssociation
@param array<string, mixed> $metadata
public function sync(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $model, array|Illuminate\Support\Collection $media, string $collection="default"): void
@param (array<int, Media> | Collection<int, Media>) $media
resolved @param (array<int, Nvl\Media\Models\Media> | Illuminate\Support\Collection<int, Nvl\Media\Models\Media>) $media
```

## Nvl\Media\Services\MediaDeliveryPolicy

```text
final class Nvl\Media\Services\MediaDeliveryPolicy
public function assertExpiration(string $disk, DateTimeInterface $expiration): void
public function assertVisibility(string $disk, Nvl\Media\Enums\MediaVisibility $visibility): void
public function maximumTtl(string $disk): int
public function mode(string $disk, Nvl\Media\Enums\MediaVisibility $visibility, ?Nvl\Media\Enums\MediaDeliveryMode $override=null): Nvl\Media\Enums\MediaDeliveryMode
public function validate(): void
```

## Nvl\Media\Services\MediaImport

```text
final class Nvl\Media\Services\MediaImport
public function __construct(Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaFileExistence $existence): mixed
public function adopt(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceTable, ?string $translationTable=null, ?string $variationTable=null, ?string $uploaderType=null, string $defaultLocale="en", bool $apply=false): array
@return array{mode: string, ready: bool, sourceMedia: int, sourceAssociations: int, sourceTranslations: int, sourceVariations: int, matchedMedia: int, matchedAssociations: int, matchedTranslations: int, matchedVariations: int, missingPaths: list<string>, errors: list<string>}
@throws InvalidArgumentException
@throws LogicException
```

## Nvl\Media\Services\MediaLibrary

```text
final class Nvl\Media\Services\MediaLibrary
public function __construct(Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Services\MediaMutationLock $mutationLock, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Translatable\Services\RelatedTranslationStore $translations, Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaDiskGuard $diskGuard, Nvl\Media\Services\MediaFileEffectScheduler $fileEffects, Nvl\Media\Services\MediaFileExistence $existence, Nvl\Media\Services\MediaFileOperator $files, Nvl\Support\Events\ConnectionCommitCallbacks $eventCommits, Nvl\Media\Services\MediaPathResolver $paths, Nvl\Media\Services\MediaReader $queries, Nvl\Media\Services\MediaPathResolver $pathResolver, Nvl\Media\Ownership\MediaOwnershipParentResolver $owners): mixed
public function bulkDelete(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids): int
@param array<int, string> $ids
@return int
public function bulkTag(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, array $tags): int
@param array<int, string> $ids
@param array<int, string> $tags
@return int
public function delete(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, bool $force=false, ?Illuminate\Database\Eloquent\Model $owner=null): bool
public function move(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, string $folder): int
@param list<string> $ids
@throws Throwable
public function purgeDeletedFiles(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, ?Illuminate\Database\Eloquent\Model $owner=null): bool
@throws MediaUploadException
resolved @throws Nvl\Media\Exceptions\MediaUploadException
public function relocate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, string $disk, Nvl\Media\Enums\MediaVisibility $visibility, ?int $expectedRevision=null): Nvl\Media\Models\Media
@throws MediaUploadException
resolved @throws Nvl\Media\Exceptions\MediaUploadException
@throws Throwable
public function rename(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, string $filename): Nvl\Media\Models\Media
@param (Media | string) $media
resolved @param (Nvl\Media\Models\Media | string) $media
@param string $filename
@return Media
resolved @return Nvl\Media\Models\Media
public function tag(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $add=[], array $remove=[]): Nvl\Media\Models\Media
@param list<string> $add
@param list<string> $remove
public function updateMetadata(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $data): Nvl\Media\Models\Media
@param array{isPublic?: bool, tags?: (list<string> | null), metadata?: (array<string, mixed> | null), translations?: (array<string, array{title?: (string | null), alt?: (string | null), caption?: (string | null), description?: (string | null)}> | null), translation_mode?: TranslationSyncMode} $data
resolved @param array{isPublic?: bool, tags?: (list<string> | null), metadata?: (array<string, mixed> | null), translations?: (array<string, array{title?: (string | null), alt?: (string | null), caption?: (string | null), description?: (string | null)}> | null), translation_mode?: Nvl\Translatable\Enums\TranslationSyncMode} $data
```

## Nvl\Media\Services\MediaModelRegistry

```text
final class Nvl\Media\Services\MediaModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function mediaClass(): string
@return class-string<Media>
resolved @return class-string<Nvl\Media\Models\Media>
```

## Nvl\Media\Services\MediaMultipart

```text
final class Nvl\Media\Services\MediaMultipart
public function __construct(Nvl\Media\Services\MediaDiskGuard $diskGuard, Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaPathResolver $paths, Nvl\Media\Contracts\MediaAuthorization $authorization, Nvl\Media\Contracts\MultipartUploadGateway $gateway, Nvl\Media\Services\MediaFileTypePolicy $fileTypes, Nvl\Media\Services\MediaMultipartSessionMapper $sessionMapper, Nvl\Support\Ownership\Contracts\OwnershipBoundary $tenantBoundary, Nvl\Support\Logging\PackageLogger $packageLogger, Nvl\Media\Services\MediaFileEffectScheduler $fileEffects, Nvl\Media\Services\MediaMultipartLock $lock, Nvl\Support\Ownership\Contracts\OwnershipWriter $ownershipWriter): mixed
public function abort(string $uploadId, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
public function complete(array $completion, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Media\Models\Media
@param array<string, mixed> $completion
public function initiate(array $data, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@param array<string, mixed> $data
@return array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: \DateTimeImmutable, minimumPartSize: int, maximumParts: int}
resolved @return array{uploadId: string, disk: string, objectKey: string, filename: string, mimeType: string, size: int, checksum: string, visibility: Nvl\Media\Enums\MediaVisibility, uploaderId: (int | string | null), uploaderType: (string | null), expiresAt: DateTimeImmutable, minimumPartSize: int, maximumParts: int}
public function signPart(array $part, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@param array<string, mixed> $part
@return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: \DateTimeInterface}
resolved @return array{partNumber: int, url: string, headers: array<string, string>, expiresAt: DateTimeInterface}
```

## Nvl\Media\Services\MediaOwnerBatchReader

```text
final readonly class Nvl\Media\Services\MediaOwnerBatchReader
public function __construct(Nvl\Media\Support\MediaBatchOwnerAdmission $owners, Nvl\Media\Services\MediaBatchQueryBuilder $queries, Nvl\Media\Contracts\MediaBatchAuthorization $policy, Nvl\Media\Services\MediaLocaleResolver $locales, Nvl\Support\Contracts\LocaleCatalog $catalog, Nvl\Translatable\Services\TranslationResolver $translations, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Media\Services\MediaUrlResolver $urls, Nvl\Support\Ownership\Services\OwnershipResourceRegistry $ownershipResources, Nvl\Media\Ownership\MediaDeliveryGuard $delivery): mixed
public function read(array $owners, array $slots, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null): array
@param list<Model> $owners
resolved @param list<Illuminate\Database\Eloquent\Model> $owners
@param list<string> $slots
@return array{owners: array<string, array<array-key, array<string, mixed>>>, order: list<array{type: string, id: string}>}
```

## Nvl\Media\Services\MediaReader

```text
final class Nvl\Media\Services\MediaReader
public function __construct(Nvl\Media\Services\MediaLocaleResolver $localeResolver, Nvl\Media\Contracts\MediaSearchDriver $searchDriver, Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Ownership\MediaOwnershipParentResolver $owners): mixed
public function filterOptions(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $user): array
@return array{typeOptions: array<int, string>, collectionOptions: array<int, string>, folderOptions: array<int, string>, mimeTypeOptions: array<int, string>, extensionOptions: array<int, string>}
public function findMany(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids): Illuminate\Database\Eloquent\Collection
@param array<int, string> $ids
@return EloquentCollection<int, Media>
resolved @return Illuminate\Database\Eloquent\Collection<int, Nvl\Media\Models\Media>
public function get(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id, bool $includeVariations=true): Nvl\Media\Models\Media
public function getMany(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $ids, bool $includeVariations=true): Illuminate\Database\Eloquent\Collection
@param array<array-key, mixed> $ids
@return EloquentCollection<int, Media>
resolved @return Illuminate\Database\Eloquent\Collection<int, Nvl\Media\Models\Media>
public function paginate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $user, array $filters=[], bool $includeVariations=false): Illuminate\Contracts\Pagination\LengthAwarePaginator
@param array{search?: string, type?: (MediaType | string | null), disk?: string, isPublic?: bool, collection?: string, tag?: string, folder?: string, mimeType?: string, extension?: string, locale?: string, associableType?: string, per_page?: int, page?: int, sortBy?: string, sortDirection?: string} $filters
resolved @param array{search?: string, type?: (Nvl\Media\Enums\MediaType | string | null), disk?: string, isPublic?: bool, collection?: string, tag?: string, folder?: string, mimeType?: string, extension?: string, locale?: string, associableType?: string, per_page?: int, page?: int, sortBy?: string, sortDirection?: string} $filters
@return LengthAwarePaginator<int, Media>
resolved @return Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Media\Models\Media>
public function slots(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model $owner, array $slots, int $limit=100): array
@param array<array-key, mixed> $slots
@return array<string, list<Media>>
resolved @return array<string, list<Nvl\Media\Models\Media>>
public function usages(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id): Illuminate\Support\Collection
@return Collection<int, MediaAssociation>
resolved @return Illuminate\Support\Collection<int, Nvl\Media\Models\MediaAssociation>
```

## Nvl\Media\Services\MediaScan

```text
final class Nvl\Media\Services\MediaScan
public function __construct(Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaFileTypePolicy $fileTypes, Nvl\Media\Services\MediaLocalFileMaterializer $materializer, Nvl\Media\Services\SvgScanner $svgScanner, Nvl\Media\Services\MediaMutationLock $mutationLock, Nvl\Media\Services\MediaVariationDispatcher $variationDispatcher, Nvl\Support\Logging\PackageLogger $packageLogger): mixed
public function finalize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, array $result): Nvl\Media\Models\Media
@param array<string, mixed> $result
```

## Nvl\Media\Services\MediaUploader

```text
final class Nvl\Media\Services\MediaUploader
public function __construct(Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaDiskGuard $diskGuard, Nvl\Media\Services\MediaFileEffectScheduler $fileEffects, Nvl\Media\Services\MediaFileExistence $existence, Nvl\Media\Services\MediaFileOperator $files, Nvl\Media\Services\MediaIngestionPipeline $ingestion, Nvl\Media\Services\MediaPathResolver $pathResolver, Nvl\Media\Services\MediaVariationDispatcher $variationDispatcher, Nvl\Media\Services\MediaDeduplicationLock $deduplicationLock, Nvl\Media\Services\MediaMutationLock $mutationLock, Nvl\Media\Services\MediaVariationDefinitionNormalizer $definitionNormalizer, Nvl\Media\Ownership\MediaOwnershipParentResolver $owners, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Support\Events\ConnectionCommitCallbacks $eventCommits, Nvl\Support\Logging\PackageLogger $packageLogger, Nvl\Media\Services\MediaReplacementStager $stager): mixed
public function replace(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media|string $media, Illuminate\Http\UploadedFile $file): Nvl\Media\Models\Media
@throws Throwable
public function upload(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Http\UploadedFile $file, string $disk, Illuminate\Database\Eloquent\Model $model, Nvl\Media\Slots\MediaSlot $slot, string $fileName, bool $isPublic=false, array $tags=[], array $metadata=[], ?string $folderOverride=null, ?bool $allowDuplicates=null, ?bool $deduplicateExisting=null, bool $skipAutoVariations=false, array $variationDefinitions=[]): Nvl\Media\Models\Media
@param array<array-key, mixed> $tags
@param array<string, mixed> $metadata
@param (string | null) $folderOverride
@param (bool | null) $allowDuplicates
@param (bool | null) $deduplicateExisting
@param bool $skipAutoVariations
@param array<string, (ConversionDefinition | array<string, mixed>)> $variationDefinitions
resolved @param array<string, (Nvl\Media\Conversions\ConversionDefinition | array<string, mixed>)> $variationDefinitions
@return Media
resolved @return Nvl\Media\Models\Media
@throws MediaUploadException
resolved @throws Nvl\Media\Exceptions\MediaUploadException
```

## Nvl\Media\Services\MediaUrlResolver

```text
final class Nvl\Media\Services\MediaUrlResolver
public function __construct(Nvl\Media\Services\MediaDiskGateway $disks, Nvl\Media\Services\MediaFileExistence $existence, Nvl\Media\Services\MediaAccessService $access): mixed
public function buildUrl(Nvl\Media\Models\Media $media, array $parameters=[], ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
@param array<string, (scalar | null)> $parameters
public function forExistingMedia(?Nvl\Media\Models\Media $media, string $variation="", ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): ?string
public function forMedia(Nvl\Media\Models\Media $media, string $variation="", ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
@param string $variation
public function forVariation(Nvl\Media\Models\MediaImageVariation $variation, ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
public function path(Nvl\Media\Models\Media $media, string $variation=""): string
public function publicUrl(Nvl\Media\Models\Media $media, array $parameters=[], ?Nvl\Media\Enums\MediaDeliveryMode $mode=null): string
@param array<string, (scalar | null)> $parameters
public function signedUrl(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, DateTimeInterface $expiration, array $parameters=[]): string
@param array<string, (scalar | null)> $parameters
public function temporaryUrl(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, DateTimeInterface $expiration, string $variation=""): string
```

## Nvl\Media\Services\MediaVariations

```text
final class Nvl\Media\Services\MediaVariations
public function __construct(Nvl\Media\Services\MediaAccessService $access, Nvl\Media\Services\MediaDiskGuard $diskGuard, Nvl\Media\Services\MediaImageTransformer $imageTransformer, Nvl\Media\Services\MediaFileEffectScheduler $fileEffects, Nvl\Media\Services\MediaFileOperator $files, Nvl\Media\Services\MediaLocalFileMaterializer $materializer, Nvl\Media\Services\MediaMutationLock $mutationLock, Nvl\Media\Services\MediaPathResolver $pathResolver, Nvl\Media\Services\MediaTemporaryFileRegistry $temporaryFiles, Nvl\Support\Logging\PackageLogger $packageLogger, Nvl\Support\Ownership\Contracts\OwnershipWriter $ownershipWriter, Nvl\Support\Ownership\Contracts\OwnershipBoundary $tenantBoundary): mixed
public function generate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Models\Media $media, Nvl\Media\Conversions\ConversionDefinition $definition, ?int $expectedRevision=null): ?Nvl\Media\Models\MediaImageVariation
@return (MediaImageVariation | null)
resolved @return (Nvl\Media\Models\MediaImageVariation | null)
```

## Nvl\Media\Services\OwnerMediaReader

```text
final readonly class Nvl\Media\Services\OwnerMediaReader
public function __construct(Nvl\Media\Support\MediaBatchOwnerAdmission $owners, Nvl\Media\Services\MediaBatchQueryBuilder $queries, Nvl\Media\Contracts\MediaBatchAuthorization $policy, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Media\Services\MediaLocaleResolver $locales, Nvl\Support\Contracts\LocaleCatalog $catalog, Nvl\Translatable\Services\TranslationResolver $translations, Nvl\Media\Ownership\MediaDeliveryGuard $delivery, Nvl\Media\Services\MediaUrlResolver $urls, Nvl\Media\Services\MediaFileExistence $files): mixed
public function read(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model&Nvl\Media\Contracts\HasNvlMedia $owner, ?string $collection=null, int $page=1, int $perPage=25, array $variations=[], ?string $locale=null): array
@param list<string> $variations
@return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, collectionCounts: stdClass, distinctAssetCount: int, originalBytes: int}
```

## Nvl\Media\Slots\MediaSlot

```text
class Nvl\Media\Slots\MediaSlot
public const string SHARING_EXCLUSIVE="exclusive"
public const string SHARING_SHARED="shared"
public function __construct(string $name): mixed
@param string $name
public function acceptsFile(Closure $callback): static
@param Closure $callback
public function acceptsMimeTypes(array $mimeTypes): static
@param array<int, (MimeType | string)> $mimeTypes
resolved @param array<int, (Nvl\Media\Enums\MimeType | string)> $mimeTypes
public function addConversion(string $name, callable $config): static
@param string $name
@param callable $config
public function convertTo(Nvl\Media\Enums\MimeType|string $format): static
@param (MimeType | string) $format
resolved @param (Nvl\Media\Enums\MimeType | string) $format
public function exclusive(): static
public function getConversionDefinitions(): array
@return array<string, ConversionDefinition>
resolved @return array<string, Nvl\Media\Conversions\ConversionDefinition>
public function getFallbackPath(string $conversion=""): string
@param string $conversion
@return string
public function getFallbackUrl(string $conversion=""): string
@param string $conversion
@return string
public function isExclusive(): bool
public function isPrivate(): bool
public function isPublic(bool $public=true): static
@param bool $public
public function isReusable(): bool
public function isShared(): bool
public function maxFileSize(int $bytes): static
@param int $bytes
public function maxSize(int $pixels): static
@param int $pixels
public function oneToOne(): static
public function onlyKeepLatest(int $max): static
@param int $max
public function path(string $template): static
@param string $template
public function privateExclusive(): static
public function publicReusable(): static
public function resolveDisk(?bool $isPublic=null): string
@param (bool | null) $isPublic
@return string
public function shared(): static
public function shouldConvertOriginal(): bool
public function singleFile(): static
public function useDisk(string $disk): static
@param string $disk
public function useFallbackPath(string $path, string $conversion=""): static
@param string $path
@param string $conversion
public function useFallbackUrl(string $url, string $conversion=""): static
@param string $url
@param string $conversion
public function withConversions(array $conversions): static
@param array<string, (ConversionDefinition | array<(int | string), mixed>)> $conversions
resolved @param array<string, (Nvl\Media\Conversions\ConversionDefinition | array<(int | string), mixed>)> $conversions
public function withQuality(int $quality): static
@param int $quality
public function withTags(array $tags): static
@param array<int, string> $tags
public array $acceptedMimeTypes=[]
@var array<int, string>
public array $conversions=[]
@var array<string, ConversionDefinition>
resolved @var array<string, Nvl\Media\Conversions\ConversionDefinition>
public ?string $convertFormat=null
@var (string | null)
public ?int $convertMaxSize=null
@var (int | null)
public ?int $convertQuality=null
@var (int | null)
public array $defaultTags=[]
@var array<int, string>
public string $disk
@var string
public array $fallbackPaths=[]
@var array<string, string>
public array $fallbackUrls=[]
@var array<string, string>
public ?Closure $fileAcceptor=null
@var (Closure | null)
public bool $isPublic=false
@var bool
public bool $isSingleFile=false
@var bool
public ?int $maxFileSize=null
@var (int | null)
public readonly string $name
@param string $name
public string $pathTemplate
@var string
public string $sharingMode
@var string
public ?int $slotSizeLimit=null
@var (int | null)
```

## Nvl\Media\Traits\InteractsWithNvlMedia

```text
trait Nvl\Media\Traits\InteractsWithNvlMedia
public function registerNvlMedia(Nvl\Media\MediaRegistration $media, ?Nvl\Media\Models\Media $asset=null): void
public function scopeHasNvlMedia(Illuminate\Database\Eloquent\Builder $query, string $slot, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Media\Contracts\MediaBatchAuthorization $policy): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Media\Traits\InteractsWithNvlMedia> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Media\Traits\InteractsWithNvlMedia>
```
