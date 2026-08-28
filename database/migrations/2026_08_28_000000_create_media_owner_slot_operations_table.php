<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Media\Support\MediaConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(
            MediaConfiguration::ownerSlotOperationConnection(),
        );
        $tableName = MediaConfiguration::ownerSlotOperationTable();

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('idempotency_key');
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 255)->nullable();
            $table->string('owner_type', 191);
            $table->string('owner_id', 255);
            $table->string('slot', 100);
            $table->string('operation', 32);
            $table->char('request_hash', 64);
            $table->string('status', 32);
            $table->uuid('result_media_id')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(
                'idempotency_key',
                'media_owner_slot_idempotency_unique',
            );
            $table->index(
                ['owner_type', 'owner_id', 'slot'],
                'media_owner_slot_owner_slot_idx',
            );
            $table->index('created_at', 'media_owner_slot_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(MediaConfiguration::ownerSlotOperationConnection())
            ->dropIfExists(MediaConfiguration::ownerSlotOperationTable());
    }
};
