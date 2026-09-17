<?php

declare(strict_types=1);

namespace Nvl\Media\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Support\MediaVariationFileNamer;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantAdoptionMetadataValidator;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use stdClass;
use Throwable;

/** Owns Media's reviewed nullable expansion, bounded backfill, and final constraints. */
final readonly class MediaAdoptionAdapter implements TenantAdoptionAdapter, TenantAdoptionMetadataValidator
{
    private const array RESOURCES = [
        'media.assets',
        'media.associations',
        'media.variations',
        'media.translations',
        'media.multipart',
        'media.slot-operations',
        'media.catalog-grants',
    ];

    /** Create the package-owned adoption boundary. */
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionMappings $mappings,
        private EffectiveTenantConnection $connections,
        private Repository $configuration,
        private MediaDiskGateway $disks,
        private MediaFileExistence $existence,
        private MediaFileOperator $files,
        private MediaTenantParentResolver $parentResolver,
        private TenantResourceRegistry $resourcesRegistry,
    ) {}

    /** Validate the exact optional Media mapping metadata. */
    public function validateAssignment(TenantAssignment $assignment): void
    {
        if (! in_array($assignment->resource, ['media.assets', 'media.multipart'], true)) {
            if ($assignment->metadata !== []) {
                throw new TenantConfigurationInvalid('Only Media roots accept adoption metadata.');
            }

            return;
        }
        if ($assignment->resource === 'media.multipart') {
            if ($assignment->metadata !== []) {
                throw new TenantConfigurationInvalid('Multipart assignments do not accept metadata.');
            }

            return;
        }
        $unknown = array_diff(array_keys($assignment->metadata), ['splits', 'source_disposition', 'expected_digest']);
        if ($unknown !== []) {
            throw new TenantConfigurationInvalid('Media assignment metadata contains unknown fields.');
        }
        $digest = $assignment->metadata['expected_digest'] ?? null;
        if (! is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new TenantConfigurationInvalid('Media expected_digest must be lowercase SHA-256.');
        }
        $splits = $assignment->metadata['splits'] ?? [];
        if (! is_array($splits) || count($splits) > 100 || ($splits !== [] && ($assignment->metadata['source_disposition'] ?? null) !== 'retain-primary')) {
            throw new TenantConfigurationInvalid('Media split metadata is invalid.');
        }
        $tenants = [];
        $destinations = [];
        foreach ($splits as $split) {
            if (! is_array($split) || array_diff(array_keys($split), ['tenant_id', 'destination_id']) !== [] || count($split) !== 2
                || ! is_string($split['tenant_id']) || ! Str::isUuid($split['tenant_id'])
                || ! is_string($split['destination_id']) || ! Str::isUuid($split['destination_id'])
                || isset($tenants[$split['tenant_id']]) || isset($destinations[$split['destination_id']])) {
                throw new TenantConfigurationInvalid('Media split identities must be unique canonical UUIDs.');
            }
            $tenants[$split['tenant_id']] = true;
            $destinations[$split['destination_id']] = true;
        }
    }

    /** @return list<string> */
    public function resources(): array
    {
        if ($this->platformOwned() && $this->parentResolver->types() === []) {
            return array_values(array_diff(self::RESOURCES, ['media.slot-operations']));
        }

        return self::RESOURCES;
    }

    /** Apply only the nullable expansion and grant schema phases. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->assertConnection($plan);
        $path = dirname(__DIR__, 2).'/database/tenancy';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([
            $path.'/2026_09_16_100001_expand_media_tenant_ownership.php',
            $path.'/2026_09_16_100002_create_media_tenant_grants.php',
        ], ['force' => true]));
    }

    /** Backfill one bounded root assignment batch and then derive every child. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $this->assertConnection($plan);
        [$phase, $after] = $this->cursor($cursor);
        if ($phase === 'assets') {
            $batch = $this->mappings->assignments($plan, 'media.assets', $after, $limit);
            if ($batch !== []) {
                foreach ($batch as $assignment) {
                    $this->backfillAsset($plan, $assignment);
                }
                $last = $batch[array_key_last($batch)];

                return new TenantBackfillResult('assets:'.$last->recordId, count($batch));
            }
            $phase = 'multipart';
            $after = null;
        }
        if ($phase === 'multipart') {
            $batch = $this->mappings->assignments($plan, 'media.multipart', $after, $limit);
            if ($batch !== []) {
                $this->connection($plan)->transaction(function () use ($batch): void {
                    foreach ($batch as $assignment) {
                        $this->backfillMultipart($assignment);
                    }
                });
                $last = $batch[array_key_last($batch)];

                return new TenantBackfillResult('multipart:'.$last->recordId, count($batch));
            }
        }
        $this->connection($plan)->transaction(fn () => $this->deriveOwnerOperations());

        return new TenantBackfillResult(null, 0);
    }

    /**
     * Verify complete mapping, child ownership, persisted paths, and schema shape.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $this->assertConnection($plan);
        $connection = $this->connection($plan);
        $schema = $connection->getSchemaBuilder();
        $errors = [];
        foreach ([MediaTables::Media, MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n, MediaTables::MultipartUploads, (new MediaOwnerSlotOperation)->getTable(), MediaTables::TenantGrants] as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'tenant_id')) {
                $errors[] = $table.'.tenant_id';
            }
        }
        $platform = $this->configuration->get('tenancy.resources.media') === 'platform';
        if ($schema->hasTable(MediaTables::Media)
            && $connection->table(MediaTables::Media)->where(function (Builder $query) use ($platform): void {
                $query->whereNull('storage_path');
                if ($platform) {
                    $query->orWhere('ownership_key', '!=', 'platform')->orWhereNotNull('tenant_id');
                } else {
                    $query->orWhereNull('tenant_id');
                }
            })->exists()) {
            $errors[] = 'media.assets.unmapped';
        }
        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
            $invalidChild = $platform
                ? $connection->table($child)->where(fn (Builder $query) => $query->whereNotNull('tenant_id')->orWhere('ownership_key', '!=', 'platform'))->exists()
                : $connection->table($child)->whereNull($child.'.tenant_id')->exists();
            $mismatchedParent = $platform
                ? $connection->table($child.' as child')->join(MediaTables::Media.' as parent', 'parent.id', '=', 'child.media_id')->whereColumn('child.ownership_key', '!=', 'parent.ownership_key')->exists()
                : $connection->table($child.' as child')->join(MediaTables::Media.' as parent', 'parent.id', '=', 'child.media_id')->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->exists();
            if ($schema->hasTable($child) && ($invalidChild || $mismatchedParent)) {
                $errors[] = $child.'.ownership';
            }
        }
        $invalidMultipart = $platform
            ? $connection->table(MediaTables::MultipartUploads)->where(fn (Builder $query) => $query->whereNotNull('tenant_id')->orWhere('ownership_key', '!=', 'platform'))->exists()
            : $connection->table(MediaTables::MultipartUploads)->whereNull('tenant_id')->exists();
        if ($schema->hasTable(MediaTables::MultipartUploads) && $invalidMultipart) {
            $errors[] = 'media.multipart.unmapped';
        }
        if ($schema->hasTable((new MediaOwnerSlotOperation)->getTable()) && $connection->table((new MediaOwnerSlotOperation)->getTable())->whereNull('tenant_id')->exists()) {
            $errors[] = 'media.slot-operations.unmapped';
        }
        if ($schema->hasTable(MediaTables::TenantAdoptionCopies)
            && $connection->table(MediaTables::TenantAdoptionCopies)->where('adoption_run_id', $plan->id)->where('status', '!=', 'committed')->exists()) {
            $errors[] = 'media.assets.copy_incomplete';
        }
        $errors = [...$errors, ...$this->verifyReviewedCopies($plan), ...$this->verifyReviewedMultipart($plan)];

        return new TenantVerification(array_slice(array_values(array_unique($errors)), 0, 100));
    }

    /** Apply final constraints only after verification succeeds. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verified($plan)) {
            throw new TenantBoundaryViolation('Media tenant schema did not verify before activation.');
        }
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_100003_constrain_media_tenant_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
        if (! $this->verified($plan)) {
            throw new TenantBoundaryViolation('Media tenant schema did not verify after activation.');
        }
    }

    /**
     * Re-run mutable database verification across an adoption phase transition.
     *
     * @phpstan-impure
     */
    private function verified(TenantAdoptionPlan $plan): bool
    {
        return $this->verify($plan)->passed();
    }

    /** Backfill one asset plus every canonical child row. */
    private function backfillAsset(TenantAdoptionPlan $plan, TenantAssignment $assignment): void
    {
        $connection = $this->connection($plan);
        $row = $connection->table(MediaTables::Media)->where('id', $assignment->recordId)->first();
        if (! $row instanceof stdClass) {
            throw new TenantBoundaryViolation('A reviewed Media asset is unavailable.');
        }
        $source = $this->sourceFacts($row);
        $expected = $assignment->metadata['expected_digest'];
        if (! is_string($expected) || ! hash_equals($expected, $source['digest'])) {
            throw new TenantBoundaryViolation('A reviewed Media digest changed.');
        }
        if ($this->platformOwned()) {
            $sourcePath = $this->legacyPath($source);
            if (! $this->verifiedObject($source['disk'], $sourcePath, $source['digest'], $source['size'])) {
                throw new TenantBoundaryViolation('A reviewed Media source binary is unavailable or changed.');
            }
            $connection->transaction(function () use ($assignment, $connection, $sourcePath): void {
                $ownership = ['tenant_id' => null, 'ownership_key' => 'platform'];
                $connection->table(MediaTables::Media)->where('id', $assignment->recordId)->update([
                    ...$ownership,
                    'storage_path' => $sourcePath,
                ]);
                foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
                    $connection->table($child)->where('media_id', $assignment->recordId)->update($ownership);
                }
            });

            return;
        }
        $committed = $connection->table(MediaTables::TenantAdoptionCopies)
            ->where('adoption_run_id', $plan->id)
            ->where('source_id', $assignment->recordId)
            ->where('status', 'committed')
            ->count();
        $destinations = $this->destinations($assignment);
        if ($committed === count($destinations) && $this->reviewedCopyGraphIsValid($plan, $assignment, $source, $destinations)) {
            return;
        }
        if ($committed > 0) {
            throw new TenantBoundaryViolation('A committed Media adoption graph is incomplete or corrupt.');
        }

        $sourcePath = $this->legacyPath($source);
        if (! $this->verifiedObject($source['disk'], $sourcePath, $source['digest'], $source['size'])) {
            throw new TenantBoundaryViolation('A reviewed Media source binary is unavailable or changed.');
        }

        $associations = $connection->table(MediaTables::Associations)->where('media_id', $assignment->recordId)->get();
        $associationTenants = [];
        $associationDestinations = [];
        foreach ($associations as $association) {
            if (! is_string($association->id)) {
                throw new TenantBoundaryViolation('A reviewed Media association identity is invalid.');
            }
            $tenant = $this->associationTenant($association);
            $associationTenants[$tenant] = true;
            $associationDestinations[$association->id] = $tenant;
        }
        ksort($associationTenants);
        if (array_keys($associationTenants) !== array_keys($destinations)) {
            throw new TenantBoundaryViolation('Media split metadata does not match the complete reviewed owner graph.');
        }

        $variations = $connection->table(MediaTables::ImageVariations)->where('media_id', $assignment->recordId)->get();
        $prepared = [];
        foreach ($destinations as $tenant => $destinationId) {
            $targetPath = $this->tenantPath($tenant, $source['folder'], $source['hash']);
            $this->recordCopy($plan, $source, $tenant, $destinationId, $targetPath);
            $this->copyVerified($source['disk'], $sourcePath, $targetPath, $source['digest'], $source['size']);
            $variationPaths = [];
            foreach ($variations as $variation) {
                $variationFacts = $this->variationFacts($variation);
                $sourceVariation = $this->legacyVariationPath($source, $variationFacts, $sourcePath);
                $targetVariation = dirname($targetPath).'/'.$this->conversionsFolder().'/'.basename($sourceVariation);
                $this->copyVerified(
                    $source['disk'],
                    $sourceVariation,
                    $targetVariation,
                    $this->disks->checksum($source['disk'], $sourceVariation),
                    $variationFacts['size'],
                );
                $variationPaths[$variationFacts['id']] = $targetVariation;
            }
            $connection->table(MediaTables::TenantAdoptionCopies)
                ->where('adoption_run_id', $plan->id)
                ->where('source_id', $assignment->recordId)
                ->where('tenant_id', $tenant)
                ->update(['status' => 'verified', 'checksum_verified_at' => now(), 'updated_at' => now()]);
            $prepared[$tenant] = ['id' => $destinationId, 'path' => $targetPath, 'variations' => $variationPaths];
        }

        $this->persistSplitGraph($plan, $assignment, $source, $destinations, $prepared, $associationDestinations, array_values($variations->all()));
    }

    /** @return array<string, string> */
    private function destinations(TenantAssignment $assignment): array
    {
        $destinations = [$assignment->tenantId->value => $assignment->recordId];
        $splits = $assignment->metadata['splits'] ?? [];
        if (! is_array($splits)) {
            throw new TenantConfigurationInvalid('Media split metadata is invalid.');
        }
        foreach ($splits as $split) {
            if (! is_array($split) || ! is_string($split['tenant_id'] ?? null) || ! is_string($split['destination_id'] ?? null)) {
                throw new TenantConfigurationInvalid('Media split metadata is invalid.');
            }
            $destinations[$split['tenant_id']] = $split['destination_id'];
        }
        ksort($destinations);

        return $destinations;
    }

    /** @param array{id: string, digest: string, hash: string, disk: string, folder: string, size: int, storage_path: string|null} $source */
    private function legacyPath(array $source): string
    {
        if ($source['storage_path'] !== null && $source['storage_path'] !== '') {
            return $source['storage_path'];
        }

        return $this->legacyFolder($source['folder']).'/'.$source['hash'];
    }

    private function legacyFolder(string $folder): string
    {
        $root = $this->configuration->get('media.root_folder', 'media');
        if (! is_string($root)) {
            throw new TenantConfigurationInvalid('media.root_folder must be a string.');
        }

        return implode('/', array_filter([trim($root, '/'), trim($folder, '/')], static fn (string $part): bool => $part !== ''));
    }

    private function tenantPath(string $tenant, string $folder, string $filename): string
    {
        $root = $this->configuration->get('media.root_folder', 'media');
        if (! is_string($root)) {
            throw new TenantConfigurationInvalid('media.root_folder must be a string.');
        }

        return implode('/', array_filter([trim($root, '/'), 'tenants', $tenant, trim($folder, '/'), $filename], static fn (string $part): bool => $part !== ''));
    }

    private function conversionsFolder(): string
    {
        $folder = $this->configuration->get('media.conversions_folder', 'conversions');
        if (! is_string($folder) || trim($folder, '/') === '') {
            throw new TenantConfigurationInvalid('media.conversions_folder must be a non-empty string.');
        }

        return trim($folder, '/');
    }

    /** @return array{id: string, digest: string, hash: string, disk: string, folder: string, size: int, storage_path: string|null} */
    private function sourceFacts(stdClass $row): array
    {
        if (! is_string($row->id) || ! is_string($row->digest) || ! is_string($row->hash) || ! is_string($row->disk)
            || (! is_string($row->folder) && $row->folder !== null)
            || (! is_string($row->storage_path) && $row->storage_path !== null)) {
            throw new TenantBoundaryViolation('A reviewed Media asset has invalid storage facts.');
        }

        return [
            'id' => $row->id,
            'digest' => $row->digest,
            'hash' => $row->hash,
            'disk' => $row->disk,
            'folder' => $row->folder ?? '',
            'size' => $this->nonNegativeInteger($row->size, 'Media asset size'),
            'storage_path' => $row->storage_path,
        ];
    }

    /** @return array{id: string, label: string, format: string, width: int, height: int, size: int, storage_path: string|null} */
    private function variationFacts(stdClass $variation): array
    {
        if (! is_string($variation->id) || ! is_string($variation->label) || ! is_string($variation->format)
            || (! is_string($variation->storage_path) && $variation->storage_path !== null)) {
            throw new TenantBoundaryViolation('A reviewed Media variation has invalid storage facts.');
        }

        return [
            'id' => $variation->id,
            'label' => $variation->label,
            'format' => $variation->format,
            'width' => $this->nonNegativeInteger($variation->width, 'Media variation width'),
            'height' => $this->nonNegativeInteger($variation->height, 'Media variation height'),
            'size' => $this->nonNegativeInteger($variation->size, 'Media variation size'),
            'storage_path' => $variation->storage_path,
        ];
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return intval($value);
        }

        throw new TenantBoundaryViolation("{$field} is invalid.");
    }

    /**
     * @param  array{id: string, digest: string, hash: string, disk: string, folder: string, size: int, storage_path: string|null}  $media
     * @param  array{id: string, label: string, format: string, width: int, height: int, size: int, storage_path: string|null}  $variation
     */
    private function legacyVariationPath(array $media, array $variation, string $sourcePath): string
    {
        if ($variation['storage_path'] !== null && $variation['storage_path'] !== '') {
            return $variation['storage_path'];
        }
        $filename = MediaVariationFileNamer::make(
            $media['hash'],
            $variation['label'],
            $variation['width'],
            $variation['height'],
            $variation['format'],
        );

        return dirname($sourcePath).'/'.$this->conversionsFolder().'/'.$filename;
    }

    private function associationTenant(stdClass $association): string
    {
        if (! is_string($association->associable_type) || ! is_string($association->associable_id)) {
            throw new TenantBoundaryViolation('A reviewed Media association owner is invalid.');
        }
        $class = Relation::getMorphedModel($association->associable_type) ?? $association->associable_type;
        $allowed = $this->parentResolver->types();
        if (! is_a($class, Model::class, true) || ! in_array($class, $allowed, true)) {
            throw new TenantBoundaryViolation('A reviewed Media association owner type is unknown.');
        }
        $owner = new $class;
        $this->resourcesRegistry->forModel($owner);
        if ($owner->getConnection() !== $this->connectionByName()) {
            throw new TenantBoundaryViolation('A reviewed Media association owner uses another connection.');
        }
        $tenant = $owner->getConnection()->table($owner->getTable())
            ->where($owner->getKeyName(), $association->associable_id)
            ->value('tenant_id');
        if (! is_string($tenant)) {
            throw new TenantBoundaryViolation('A reviewed Media association owner is unmapped.');
        }

        return $tenant;
    }

    /** @param array{id: string, digest: string, hash: string, disk: string, folder: string, size: int, storage_path: string|null} $source */
    private function recordCopy(TenantAdoptionPlan $plan, array $source, string $tenant, string $destinationId, string $path): void
    {
        $connection = $this->connection($plan);
        $identity = ['adoption_run_id' => $plan->id, 'source_id' => $source['id'], 'tenant_id' => $tenant];
        $existing = $connection->table(MediaTables::TenantAdoptionCopies)->where($identity)->first();
        $attributes = [
            'destination_id' => $destinationId,
            'status' => 'copying',
            'disk' => $source['disk'],
            'storage_path' => $path,
            'digest' => $source['digest'],
            'checksum_verified_at' => null,
            'updated_at' => now(),
        ];
        if ($existing === null) {
            $connection->table(MediaTables::TenantAdoptionCopies)->insert([...$identity, ...$attributes, 'created_at' => now()]);

            return;
        }
        if ($existing->destination_id !== $destinationId || $existing->disk !== $source['disk']
            || $existing->storage_path !== $path || $existing->digest !== $source['digest']) {
            throw new TenantBoundaryViolation('A durable Media adoption copy mapping changed.');
        }
        if ($existing->status !== 'committed') {
            $connection->table(MediaTables::TenantAdoptionCopies)->where($identity)->update($attributes);
        }
    }

    private function copyVerified(string $disk, string $source, string $destination, string $digest, int $size): void
    {
        if ($this->existence->exists($disk, $destination)) {
            if (! $this->verifiedObject($disk, $destination, $digest, $size)) {
                throw new TenantBoundaryViolation('A Media adoption destination collides with different bytes.');
            }

            return;
        }
        if (! $this->files->copy($disk, $source, $disk, $destination, MediaVisibility::Private)
            || ! $this->verifiedObject($disk, $destination, $digest, $size)) {
            throw new TenantBoundaryViolation('A Media adoption binary could not be copied and verified.');
        }
    }

    private function verifiedObject(string $disk, string $path, string $digest, int $size): bool
    {
        try {
            return $this->existence->exists($disk, $path)
                && $this->disks->size($disk, $path) === $size
                && hash_equals($digest, $this->disks->checksum($disk, $path));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Commit the complete copied graph only after every destination object verifies.
     *
     * @param  array{id: string, digest: string, hash: string, disk: string, folder: string, size: int, storage_path: string|null}  $source
     * @param  array<string, string>  $destinations
     * @param  array<string, array{id: string, path: string, variations: array<string, string>}>  $prepared
     * @param  array<string, string>  $associations
     * @param  list<stdClass>  $variations
     */
    private function persistSplitGraph(
        TenantAdoptionPlan $plan,
        TenantAssignment $assignment,
        array $source,
        array $destinations,
        array $prepared,
        array $associations,
        array $variations,
    ): void {
        $connection = $this->connection($plan);
        $translations = $connection->table(MediaTables::I18n)->where('media_id', $assignment->recordId)->get()->all();

        $connection->transaction(function () use ($plan, $assignment, $source, $destinations, $prepared, $associations, $variations, $translations, $connection): void {
            $locked = $connection->table(MediaTables::Media)->where('id', $assignment->recordId)->lockForUpdate()->first();
            if (! $locked instanceof stdClass || $locked->tenant_id !== null || ! is_string($locked->digest)
                || ! hash_equals($source['digest'], $locked->digest)) {
                throw new TenantBoundaryViolation('The reviewed Media source changed before graph commit.');
            }

            foreach ($destinations as $tenant => $destinationId) {
                $ownership = $this->ownership($tenant);
                if ($destinationId !== $assignment->recordId) {
                    if ($connection->table(MediaTables::Media)->where('id', $destinationId)->exists()) {
                        throw new TenantBoundaryViolation('A reviewed Media split destination already exists.');
                    }
                    $copy = (array) $locked;
                    $copy['id'] = $destinationId;
                    $copy['storage_path'] = $prepared[$tenant]['path'];
                    $copy = [...$copy, ...$ownership];
                    $connection->table(MediaTables::Media)->insert($copy);
                }

                foreach ($translations as $translation) {
                    if ($destinationId === $assignment->recordId) {
                        continue;
                    }
                    $copy = (array) $translation;
                    $copy['id'] = (string) Str::uuid();
                    $copy['media_id'] = $destinationId;
                    $connection->table(MediaTables::I18n)->insert([...$copy, ...$ownership]);
                }
                foreach ($variations as $variation) {
                    if ($destinationId === $assignment->recordId) {
                        continue;
                    }
                    $copy = (array) $variation;
                    $variationFacts = $this->variationFacts($variation);
                    $copy['id'] = (string) Str::uuid();
                    $copy['media_id'] = $destinationId;
                    $copy['storage_path'] = $prepared[$tenant]['variations'][$variationFacts['id']];
                    $connection->table(MediaTables::ImageVariations)->insert([...$copy, ...$ownership]);
                }
            }

            $primaryTenant = $assignment->tenantId->value;
            $primaryOwnership = $this->ownership($primaryTenant);
            $connection->table(MediaTables::Media)->where('id', $assignment->recordId)->update([
                ...$primaryOwnership,
                'storage_path' => $prepared[$primaryTenant]['path'],
            ]);
            $connection->table(MediaTables::I18n)->where('media_id', $assignment->recordId)->update($primaryOwnership);
            foreach ($variations as $variation) {
                $variationFacts = $this->variationFacts($variation);
                $connection->table(MediaTables::ImageVariations)->where('id', $variationFacts['id'])->update([
                    ...$primaryOwnership,
                    'storage_path' => $prepared[$primaryTenant]['variations'][$variationFacts['id']],
                ]);
            }
            foreach ($associations as $associationId => $tenant) {
                $connection->table(MediaTables::Associations)->where('id', $associationId)->update([
                    ...$this->ownership($tenant),
                    'media_id' => $destinations[$tenant],
                ]);
            }
            $connection->table(MediaTables::TenantAdoptionCopies)
                ->where('adoption_run_id', $plan->id)
                ->where('source_id', $assignment->recordId)
                ->update(['status' => 'committed', 'updated_at' => now()]);
        });
    }

    /** Backfill one independently mapped multipart root and its physical object. */
    private function backfillMultipart(TenantAssignment $assignment): void
    {
        $connection = $this->connectionByName();
        $row = $connection->table(MediaTables::MultipartUploads)->where('id', $assignment->recordId)->lockForUpdate()->first();
        if (! $row instanceof stdClass || ! is_string($row->disk) || ! is_string($row->object_key)
            || ! is_string($row->expected_checksum)) {
            throw new TenantBoundaryViolation('A reviewed Media root is unavailable.');
        }
        $size = $this->nonNegativeInteger($row->expected_size, 'Multipart expected size');
        if (! $this->verifiedObject($row->disk, $row->object_key, $row->expected_checksum, $size)) {
            throw new TenantBoundaryViolation('A reviewed multipart binary is unavailable or changed.');
        }
        if ($this->platformOwned()) {
            $connection->table(MediaTables::MultipartUploads)->where('id', $assignment->recordId)->update([
                'tenant_id' => null,
                'ownership_key' => 'platform',
            ]);

            return;
        }
        $path = $this->tenantPath($assignment->tenantId->value, 'multipart', basename($row->object_key));
        $this->copyVerified($row->disk, $row->object_key, $path, $row->expected_checksum, $size);
        $connection->table(MediaTables::MultipartUploads)->where('id', $assignment->recordId)->update([
            ...$this->ownership($assignment->tenantId->value),
            'object_key' => $path,
            'object_key_hash' => hash('sha256', $path),
        ]);
    }

    /** @return list<string> */
    private function verifyReviewedCopies(TenantAdoptionPlan $plan): array
    {
        if ($this->platformOwned()) {
            return [];
        }
        $errors = [];
        $after = null;
        do {
            $assignments = $this->mappings->assignments($plan, 'media.assets', $after, 100);
            foreach ($assignments as $assignment) {
                $row = $this->connection($plan)->table(MediaTables::Media)->where('id', $assignment->recordId)->first();
                if (! $row instanceof stdClass || ! $this->reviewedCopyGraphIsValid($plan, $assignment, $this->sourceFacts($row), $this->destinations($assignment))) {
                    $errors[] = 'media.assets.copy_graph:'.$assignment->recordId;
                }
                $after = $assignment->recordId;
            }
        } while ($assignments !== [] && count($errors) < 100);

        return $errors;
    }

    /** @return list<string> */
    private function verifyReviewedMultipart(TenantAdoptionPlan $plan): array
    {
        $errors = [];
        $after = null;
        do {
            $assignments = $this->mappings->assignments($plan, 'media.multipart', $after, 100);
            foreach ($assignments as $assignment) {
                $row = $this->connection($plan)->table(MediaTables::MultipartUploads)->where('id', $assignment->recordId)->first();
                $validOwnership = $this->platformOwned()
                    ? $row instanceof stdClass && ($row->tenant_id ?? null) === null && ($row->ownership_key ?? null) === 'platform'
                    : $row instanceof stdClass && $this->rowMatchesOwnership($row, $assignment->tenantId->value);
                if (! $validOwnership || ! is_string($row->disk ?? null) || ! is_string($row->object_key ?? null)
                    || ! is_string($row->expected_checksum ?? null)
                    || ! $this->verifiedObject($row->disk, $row->object_key, $row->expected_checksum, $this->nonNegativeInteger($row->expected_size, 'Multipart expected size'))) {
                    $errors[] = 'media.multipart.binary:'.$assignment->recordId;
                }
                $after = $assignment->recordId;
            }
        } while ($assignments !== [] && count($errors) < 100);

        return $errors;
    }

    /**
     * @param  array{id: string, digest: string, hash: string, disk: string, folder: string, size: int, storage_path: string|null}  $source
     * @param  array<string, string>  $destinations
     */
    private function reviewedCopyGraphIsValid(TenantAdoptionPlan $plan, TenantAssignment $assignment, array $source, array $destinations): bool
    {
        $connection = $this->connection($plan);
        $expectedTranslations = $connection->table(MediaTables::I18n)->where('media_id', $assignment->recordId)->count();
        $sourceVariations = $connection->table(MediaTables::ImageVariations)->where('media_id', $assignment->recordId)->get();
        $variationDigests = [];
        foreach ($sourceVariations as $variation) {
            if (! $variation instanceof stdClass || ! is_string($variation->storage_path)) {
                return false;
            }
            $facts = $this->variationFacts($variation);
            if (! $this->existence->exists($source['disk'], $variation->storage_path)) {
                return false;
            }
            $variationDigests[$this->variationSignature($facts)] = $this->disks->checksum($source['disk'], $variation->storage_path);
        }
        foreach ($destinations as $tenant => $destinationId) {
            $ledger = $connection->table(MediaTables::TenantAdoptionCopies)
                ->where('adoption_run_id', $plan->id)->where('source_id', $assignment->recordId)->where('tenant_id', $tenant)->first();
            $root = $connection->table(MediaTables::Media)->where('id', $destinationId)->first();
            if (! $ledger instanceof stdClass || $ledger->status !== 'committed' || $ledger->destination_id !== $destinationId
                || ! $root instanceof stdClass || ! $this->rowMatchesOwnership($root, $tenant) || $root->storage_path !== $ledger->storage_path
                || ! is_string($ledger->disk) || ! is_string($ledger->storage_path) || ! is_string($ledger->digest)
                || ! is_string($root->digest) || ! hash_equals($source['digest'], $ledger->digest) || ! hash_equals($source['digest'], $root->digest)
                || ! $this->verifiedObject($ledger->disk, $ledger->storage_path, $ledger->digest, $source['size'])
                || $connection->table(MediaTables::I18n)->where('media_id', $destinationId)->count() !== $expectedTranslations
                || $connection->table(MediaTables::ImageVariations)->where('media_id', $destinationId)->count() !== $sourceVariations->count()) {
                return false;
            }
            foreach ([MediaTables::I18n, MediaTables::Associations, MediaTables::ImageVariations] as $child) {
                foreach ($connection->table($child)->where('media_id', $destinationId)->get() as $row) {
                    if (! $row instanceof stdClass || ! $this->rowMatchesOwnership($row, $tenant)) {
                        return false;
                    }
                    if ($child === MediaTables::Associations && $this->associationTenant($row) !== $tenant) {
                        return false;
                    }
                    if ($child === MediaTables::ImageVariations) {
                        $facts = $this->variationFacts($row);
                        $digest = $variationDigests[$this->variationSignature($facts)] ?? null;
                        if (! is_string($row->storage_path) || ! is_string($digest)
                            || ! $this->verifiedObject($source['disk'], $row->storage_path, $digest, $facts['size'])) {
                            return false;
                        }
                    }
                }
            }
            if (! $connection->table(MediaTables::Associations)->where('media_id', $destinationId)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @param array{id: string, label: string, format: string, width: int, height: int, size: int, storage_path: string|null} $variation */
    private function variationSignature(array $variation): string
    {
        return implode(':', [$variation['label'], $variation['format'], $variation['width'], $variation['height'], $variation['size']]);
    }

    private function rowMatchesOwnership(stdClass $row, string $tenant): bool
    {
        if (($row->tenant_id ?? null) !== $tenant) {
            return false;
        }

        return $this->configuration->get('tenancy.sharing.media') !== 'copy'
            || ($row->ownership_key ?? null) === 'tenant:'.$tenant;
    }

    /** Derive operation tenant identity from persisted canonical owners. */
    private function deriveOwnerOperations(): void
    {
        $connection = $this->connectionByName();
        foreach ($connection->table((new MediaOwnerSlotOperation)->getTable())->whereNull('tenant_id')->orderBy('id')->get() as $operation) {
            if (! is_string($operation->owner_type) || ! is_string($operation->owner_id)) {
                throw new TenantBoundaryViolation('A Media operation owner identity is invalid.');
            }
            $class = Relation::getMorphedModel($operation->owner_type) ?? $operation->owner_type;
            if (! is_a($class, Model::class, true)) {
                throw new TenantBoundaryViolation('A Media operation owner type is unknown.');
            }
            $owner = (new $class)->newQuery()->whereKey($operation->owner_id)->first();
            $tenant = $owner?->getAttribute('tenant_id');
            if (! is_string($tenant)) {
                throw new TenantBoundaryViolation('A Media operation owner is unmapped.');
            }
            $connection->table((new MediaOwnerSlotOperation)->getTable())->where('id', $operation->id)->update(['tenant_id' => $tenant]);
        }
    }

    /** @return array{tenant_id: string|null, ownership_key?: string} */
    private function ownership(string $tenant): array
    {
        $attributes = ['tenant_id' => $tenant];
        if ($this->configuration->get('tenancy.sharing.media') === 'copy') {
            $attributes['ownership_key'] = 'tenant:'.$tenant;
        }

        return $attributes;
    }

    private function platformOwned(): bool
    {
        return $this->configuration->get('tenancy.resources.media') === 'platform';
    }

    /** @return array{string, ?string} */
    private function cursor(?string $cursor): array
    {
        if ($cursor === null) {
            return ['assets', null];
        }
        $parts = explode(':', $cursor, 2);
        if (count($parts) !== 2 || ! in_array($parts[0], ['assets', 'multipart'], true) || $parts[1] === '') {
            throw new TenantConfigurationInvalid('The Media adoption cursor is invalid.');
        }

        return [$parts[0], $parts[1]];
    }

    /** Resolve the run's exact canonical connection. */
    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = $this->connections->core();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('Media adoption plan connection changed.');
        }

        return $connection;
    }

    /** Resolve the canonical connection after the plan has already been checked. */
    private function connectionByName(): Connection
    {
        return (new Media)->getConnection();
    }

    /** Require the run and every Media table to share the canonical connection instance. */
    private function assertConnection(TenantAdoptionPlan $plan): void
    {
        $connection = $this->connection($plan);
        foreach ([new Media, new MediaOwnerSlotOperation] as $model) {
            if ($model->getConnection() !== $connection) {
                throw new TenantBoundaryViolation('Media adoption requires one canonical connection.');
            }
        }
    }
}
