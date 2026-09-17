<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use RuntimeException;

/** Creates an isolated Media schema and publishes tenant worker probes. */
final class MediaTenancyConsumerSetupCommand extends Command
{
    protected $signature = 'media-tenancy-fixture:setup {--queue=}';

    protected $description = 'Prepare the isolated Media tenancy worker fixture.';

    /** Build, adopt, seed, and optionally queue the worker proof. */
    public function handle(
        TenantAdoptionCoordinator $coordinator,
        TenantRunner $runner,
        TenantBoundary $boundary,
        MaintenanceMode $maintenance,
        TenantContext $context,
    ): int {
        $this->createInfrastructure();
        $maintenance->activate([]);
        try {
            $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
            $plan = $coordinator->prepare(['resource-fixture-owners', 'media'], [], $operation);
            $done = false;
            for ($batch = 0; $batch < 20 && ! $done; $batch++) {
                $done = $coordinator->backfill($plan, 100, $operation);
            }
            if (! $done || ! $coordinator->verify($plan)->passed()) {
                throw new RuntimeException('Media fixture adoption did not verify.');
            }
            $coordinator->activate($plan, $operation);
        } finally {
            $maintenance->deactivate();
        }

        $a = $this->seed($runner, $boundary, MediaTenancyScenario::A, 'a.png');
        $b = $this->seed($runner, $boundary, MediaTenancyScenario::B, 'b.png');
        $failing = $this->seed($runner, $boundary, MediaTenancyScenario::A, 'failing.png');
        Storage::disk($failing->disk)->put($failing->buildPath(), 'invalid image bytes');

        $queue = $this->stringOption('queue');
        if ($queue !== null) {
            $this->publishProbes($queue, $a, $b, $failing, $runner, $context);
        }

        $this->line('driver='.DB::connection()->getDriverName().' setup=ok'.($queue !== null ? ' queue='.$queue : ''));

        return self::SUCCESS;
    }

    /** Create tenancy, Media, queue, failure, and probe schema explicitly. */
    private function createInfrastructure(): void
    {
        $root = getenv('NVL_TEST_SUITE_ROOT');
        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The fixture requires NVL_TEST_SUITE_ROOT.');
        }
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $this->runMigration($root.'/packages/nvl/tenancy/database/migrations/tenancy/2026_09_16_000001_create_tenancy_core_tables.php');
        foreach (glob($root.'/packages/nvl/media/database/migrations/*.php') ?: [] as $migration) {
            $this->runMigration($migration);
        }
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $schema->create('media_tenant_worker_probes', static function (Blueprint $table): void {
            $table->string('probe_key')->primary();
            $table->uuid('tenant_id')->nullable();
        });
    }

    /** Run one package migration object. */
    private function runMigration(string $path): void
    {
        $migration = require $path;
        if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
            throw new RuntimeException("The migration [{$path}] is invalid.");
        }
        call_user_func([$migration, 'up']);
    }

    /** Upload one actual image through Media's public ingestion action. */
    private function seed(TenantRunner $runner, TenantBoundary $boundary, string $tenant, string $name): Media
    {
        return $runner->run(new TenantId($tenant), function () use ($boundary, $name): Media {
            $owner = new TestMediaModel(['name' => 'Owner '.$name]);
            $owner->forceFill($boundary->attributes('test.media-owners'));
            $owner->saveOrFail();

            $path = tempnam(sys_get_temp_dir(), 'nvl-media-worker-');
            $image = imagecreatetruecolor(2, 2);
            if (! is_string($path) || $image === false || ! imagepng($image, $path)) {
                throw new RuntimeException('Unable to create the worker image fixture.');
            }
            imagedestroy($image);
            try {
                return app(UploadMediaAction::class)->execute(
                    new UploadedFile($path, $name, 'image/png', null, true),
                    'tenant-disk',
                    $owner,
                    new MediaSlot('default'),
                    $name,
                    skipAutoVariations: true,
                );
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });
    }

    /** Publish A, B, stale, failing, and corrupted-envelope queue records. */
    private function publishProbes(
        string $queue,
        Media $a,
        Media $b,
        Media $failing,
        TenantRunner $runner,
        TenantContext $context,
    ): void {
        try {
            Bus::dispatch((new GenerateImageVariationJob(
                $a->id,
                'unresolved',
                ['width' => 1, 'height' => 1, 'format' => 'png'],
                $a->revision,
                TenantJobEnvelope::capture($context),
            ))->onConnection('database')->onQueue($queue));
            throw new RuntimeException('Missing tenant context unexpectedly dispatched.');
        } catch (TenantBoundaryViolation) {
        }

        $this->dispatch($runner, MediaTenancyScenario::A, $a, 'proof', $queue);
        $this->dispatch($runner, MediaTenancyScenario::B, $b, 'proof', $queue);
        $this->dispatch($runner, MediaTenancyScenario::A, $a, 'stale', $queue, $a->revision + 1);
        $this->dispatch($runner, MediaTenancyScenario::A, $failing, 'failure', $queue);
        $this->dispatch($runner, MediaTenancyScenario::A, $a, 'corrupt-before-read', $queue);

        $connection = DB::connection();
        $job = $connection->table('jobs')->where('queue', $queue)->orderByDesc('id')->first();
        if (! is_object($job) || ! is_string($job->payload ?? null)) {
            throw new RuntimeException('The final serialized Media probe was not stored.');
        }
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array(data_get($payload, 'data.nvl_tenancy'))) {
            throw new RuntimeException('The final serialized Media probe has no tenant envelope.');
        }
        data_set($payload, 'data.nvl_tenancy.tenant_id', MediaTenancyScenario::B);
        $connection->table('jobs')->where('id', $job->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Dispatch one scalar variation job from an admitted producer tenant. */
    private function dispatch(
        TenantRunner $runner,
        string $tenant,
        Media $media,
        string $label,
        string $queue,
        ?int $revision = null,
    ): void {
        $runner->run(new TenantId($tenant), static function () use ($media, $label, $queue, $revision): void {
            Bus::dispatch((new GenerateImageVariationJob(
                $media->id,
                $label,
                ['width' => 1, 'height' => 1, 'format' => 'png', 'quality' => 80],
                $revision ?? $media->revision,
            ))->onConnection('database')->onQueue($queue));
        });
    }

    /** Read one optional scalar command option. */
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if ($value === null || $value === false) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The [{$key}] fixture option must be a non-empty string.");
        }

        return $value;
    }
}
