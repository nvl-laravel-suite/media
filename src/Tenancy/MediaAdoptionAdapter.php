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
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantAdoptionMetadataValidator;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use stdClass;

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
        if ($digest !== null && (! is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1)) {
            throw new TenantConfigurationInvalid('Media expected_digest must be lowercase SHA-256.');
        }
        $splits = $assignment->metadata['splits'] ?? [];
        if (! is_array($splits) || count($splits) > 100 || ($splits !== [] && ($assignment->metadata['source_disposition'] ?? null) !== 'retain-primary')) {
            throw new TenantConfigurationInvalid('Media split metadata is invalid.');
        }
        $tenants = [];
        $destinations = [];
        foreach ($splits as $split) {
            if (! is_array($split) || array_keys($split) !== ['tenant_id', 'destination_id']
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
                $this->connection($plan)->transaction(function () use ($batch, $plan): void {
                    foreach ($batch as $assignment) {
                        $this->backfillAsset($plan, $assignment);
                    }
                });
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
                        $this->backfillRoot(MediaTables::MultipartUploads, $assignment);
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
        if ($schema->hasTable(MediaTables::Media)
            && $connection->table(MediaTables::Media)->where(fn (Builder $query) => $query->whereNull('tenant_id')->orWhereNull('storage_path'))->exists()) {
            $errors[] = 'media.assets.unmapped';
        }
        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
            if ($schema->hasTable($child) && ($connection->table($child)->whereNull($child.'.tenant_id')->exists()
                || $connection->table($child.' as child')->join(MediaTables::Media.' as parent', 'parent.id', '=', 'child.media_id')->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->exists())) {
                $errors[] = $child.'.ownership';
            }
        }
        if ($schema->hasTable(MediaTables::MultipartUploads) && $connection->table(MediaTables::MultipartUploads)->whereNull('tenant_id')->exists()) {
            $errors[] = 'media.multipart.unmapped';
        }
        if ($schema->hasTable((new MediaOwnerSlotOperation)->getTable()) && $connection->table((new MediaOwnerSlotOperation)->getTable())->whereNull('tenant_id')->exists()) {
            $errors[] = 'media.slot-operations.unmapped';
        }

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
        $row = $connection->table(MediaTables::Media)->where('id', $assignment->recordId)->lockForUpdate()->first();
        if (! $row instanceof stdClass || ! is_string($row->digest) || ! is_string($row->hash) || ! is_string($row->disk)) {
            throw new TenantBoundaryViolation('A reviewed Media asset is unavailable.');
        }
        $expected = $assignment->metadata['expected_digest'] ?? null;
        if (is_string($expected) && ! hash_equals($expected, $row->digest)) {
            throw new TenantBoundaryViolation('A reviewed Media digest changed.');
        }
        if (($assignment->metadata['splits'] ?? []) !== []) {
            throw new TenantBoundaryViolation('Media split copies require explicit verified owner graph preparation.');
        }
        $folder = is_string($row->folder) ? trim($row->folder, '/') : '';
        $configuredRoot = $this->configuration->get('media.root_folder', 'media');
        if (! is_string($configuredRoot)) {
            throw new TenantConfigurationInvalid('media.root_folder must be a string.');
        }
        $root = trim($configuredRoot, '/');
        $path = implode('/', array_filter([$root, $folder, $row->hash], static fn (string $part): bool => $part !== ''));
        $attributes = $this->ownership($assignment->tenantId->value);
        $connection->table(MediaTables::Media)->where('id', $assignment->recordId)->update([...$attributes, 'storage_path' => $path]);
        foreach ([MediaTables::Associations, MediaTables::ImageVariations, MediaTables::I18n] as $child) {
            $connection->table($child)->where('media_id', $assignment->recordId)->update($attributes);
        }
    }

    /** Backfill one independently mapped root. */
    private function backfillRoot(string $table, TenantAssignment $assignment): void
    {
        if ($this->connectionByName()->table($table)->where('id', $assignment->recordId)->lockForUpdate()->update($this->ownership($assignment->tenantId->value)) !== 1) {
            throw new TenantBoundaryViolation('A reviewed Media root is unavailable.');
        }
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

    /** @return array{tenant_id: string, ownership_key?: string} */
    private function ownership(string $tenant): array
    {
        $attributes = ['tenant_id' => $tenant];
        if ($this->configuration->get('tenancy.sharing.media') === 'copy') {
            $attributes['ownership_key'] = 'tenant:'.$tenant;
        }

        return $attributes;
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
