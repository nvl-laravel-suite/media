<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use RuntimeException;

/** Owns the empty canonical Media owner fixture schema. */
final readonly class TenancyOwnerAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.media-owners'];
    }

    /** Create the owner table with direct tenant identity. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new TestMediaModel)->getTable())) {
            $schema->create((new TestMediaModel)->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'id'], 'test_media_models_tenant_id_unique');
            });
        }
    }

    /** Require an empty owner table before fixture adoption. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        if ($this->connection()->table((new TestMediaModel)->getTable())->exists()) {
            throw new RuntimeException('Media owner fixture adoption requires an empty table.');
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Verify owner columns and partition uniqueness. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = (new TestMediaModel)->getTable();
        $errors = [];
        if (! $schema->hasColumns($table, ['id', 'tenant_id', 'name', 'created_at', 'updated_at', 'deleted_at'])) {
            $errors[] = 'test.media-owners.columns';
        }
        if (! $schema->hasIndex($table, ['tenant_id', 'id'], 'unique')) {
            $errors[] = 'test.media-owners.identity';
        }

        return new TenantVerification($errors);
    }

    /** The fixture schema is constrained during preparation. */
    public function activate(TenantAdoptionPlan $plan): void {}

    /** Resolve the fixture owner's effective connection. */
    private function connection(): Connection
    {
        return (new TestMediaModel)->getConnection();
    }
}
