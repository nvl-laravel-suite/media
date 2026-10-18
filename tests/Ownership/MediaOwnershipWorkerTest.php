<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Jobs\GenerateImageVariationJob;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMedia;
use Nvl\Media\Tests\Fixtures\OwnershipMediaScenario;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Symfony\Component\Process\Process;

uses(OwnershipTestCase::class);

it('runs real captured Media workers and concurrent receipts on native PostgreSQL', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly isolated PostgreSQL persistence database.');
    }
    Storage::fake('public');
    $schema = DB::getSchemaBuilder();
    $schema->create('jobs', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $schema->create('failed_jobs', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    $schema->create('cache', static function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    $schema->create('cache_locks', static function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
    config()->set('queue.connections.database.connection', 'sqlite');
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $records = [];
    foreach ([7, 8] as $company) {
        $records[$company] = $runner->run(OwnershipCompany::query()->findOrFail($company), $actor, function (): array {
            $owner = OwnershipMediaScenario::owner();
            $media = OwnershipMediaScenario::upload($owner, true);
            Queue::connection('database')->push(new GenerateImageVariationJob($media->id, 'native', ['width' => 12, 'height' => 12, 'format' => 'png'], $media->revision));

            return ['owner' => $owner->id, 'media' => $media->id];
        });
    }
    $source = json_encode(config('database.connections.sqlite'), JSON_THROW_ON_ERROR);
    $root = Storage::disk('public')->path('');
    $worker = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ownership-worker.php', $source, json_encode(['root' => $root], JSON_THROW_ON_ERROR)], timeout: 40);
    $worker->mustRun();
    expect($worker->getOutput())->toBe('unresolved')->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->pluck('exception')->all())->toBe([]);
    foreach ([7, 8] as $company) {
        $runner->run(OwnershipCompany::query()->findOrFail($company), $actor, function () use ($records, $company): void {
            $media = MediaModelRegistry::query()->findOrFail($records[$company]['media']);
            $variation = MediaImageVariation::query()->where('media_id', $media->id)->firstOrFail();
            expect($variation->media::class)->toBe(OwnershipMedia::class);
            Storage::disk('public')->assertExists($variation->storage_path);
        });
    }
    $gate = tempnam(sys_get_temp_dir(), 'nvl-media-owner-gate-');
    $ready = [tempnam(sys_get_temp_dir(), 'nvl-media-owner-a-'), tempnam(sys_get_temp_dir(), 'nvl-media-owner-b-')];
    $processes = [];
    $key = (string) Str::uuid();
    try {
        foreach ([7, 8] as $index => $company) {
            $options = ['root' => $root, 'mode' => 'receipt', 'company' => $company, 'owner' => $records[$company]['owner'], 'key' => $key, 'gate' => $gate, 'ready' => $ready[$index]];
            $processes[] = $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ownership-worker.php', $source, json_encode($options, JSON_THROW_ON_ERROR)], timeout: 30);
            $process->start();
        }
        $deadline = microtime(true) + 15;
        while ((file_get_contents($ready[0]) !== 'ready' || file_get_contents($ready[1]) !== 'ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_get_contents($ready[0]))->toBe('ready')->and(file_get_contents($ready[1]))->toBe('ready');
        file_put_contents($gate, 'go');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
        }
        expect($processes[0]->getOutput())->not->toBe($processes[1]->getOutput());
        foreach ([7, 8] as $company) {
            $runner->run(OwnershipCompany::query()->findOrFail($company), $actor, fn () => expect(MediaOwnerSlotOperation::query()->where('idempotency_key', $key)->count())->toBe(1));
        }
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        foreach ([$gate, ...$ready] as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
    }
});
