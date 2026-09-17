<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Media\Tests\Stubs\TestMediaModel;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

it('claims the same owner-slot key concurrently in independent tenant partitions', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)
        || ! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('The tenant owner-slot race requires PostgreSQL/MySQL and pcntl.');
    }

    $scenario = MediaTenancyScenario::install();
    $ownerA = $scenario->owner($scenario::A);
    $ownerB = $scenario->owner($scenario::B);
    $key = (string) Str::uuid();
    $gate = tempnam(sys_get_temp_dir(), 'media-tenant-slot-gate-');
    $results = [
        tempnam(sys_get_temp_dir(), 'media-tenant-slot-a-'),
        tempnam(sys_get_temp_dir(), 'media-tenant-slot-b-'),
    ];
    $ready = [
        tempnam(sys_get_temp_dir(), 'media-tenant-slot-ready-a-'),
        tempnam(sys_get_temp_dir(), 'media-tenant-slot-ready-b-'),
    ];
    if (! is_string($gate) || in_array(false, $results, true) || in_array(false, $ready, true)) {
        throw new RuntimeException('The tenant owner-slot race could not allocate IPC files.');
    }
    /** @var list<string> $results */
    /** @var list<string> $ready */
    $workers = [[$scenario::A, $ownerA->id], [$scenario::B, $ownerB->id]];
    $children = [];

    try {
        foreach ($workers as $index => [$tenantId, $ownerId]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('The tenant owner-slot race could not fork.');
            }
            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }

            try {
                DB::purge();
                Container::getInstance()->forgetScopedInstances();
                file_put_contents($ready[$index], 'ready');
                while (file_get_contents($gate) !== 'go') {
                    usleep(10_000);
                }
                $operationId = app(TenantRunner::class)->run(new TenantId($tenantId), function () use ($key, $ownerId): string {
                    $owner = TestMediaModel::query()->findOrFail($ownerId);
                    $claim = app(MediaOwnerSlotIdempotency::class)->begin(
                        $key,
                        MediaActorData::system(),
                        $owner,
                        'default',
                        MediaOwnerSlotOperationType::Clear,
                        ['reason' => 'tenant-race'],
                    );
                    app(MediaOwnerSlotIdempotency::class)->complete($claim, null);

                    return $claim->operationId;
                });
                $result = ['ok' => true, 'operation_id' => $operationId];
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'message' => $exception->getMessage()];
            }
            file_put_contents($results[$index], json_encode($result, JSON_THROW_ON_ERROR));
            pcntl_exec('/usr/bin/true');
            exit(1);
        }

        $deadline = microtime(true) + 10;
        while ((file_get_contents($ready[0]) !== 'ready' || file_get_contents($ready[1]) !== 'ready') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (file_get_contents($ready[0]) !== 'ready' || file_get_contents($ready[1]) !== 'ready') {
            throw new RuntimeException('The tenant owner-slot workers did not reach the race barrier.');
        }
        file_put_contents($gate, 'go');
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $decoded = array_map(static fn (string $path): array => json_decode(
            (string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR,
        ), $results);

        expect($decoded[0]['ok'])->toBeTrue(json_encode($decoded[0], JSON_THROW_ON_ERROR))
            ->and($decoded[1]['ok'])->toBeTrue(json_encode($decoded[1], JSON_THROW_ON_ERROR))
            ->and($decoded[0]['operation_id'])->not->toBe($decoded[1]['operation_id'])
            ->and(MediaOwnerSlotOperation::withoutGlobalScope('tenant')->where('idempotency_key', $key)
                ->whereIn('tenant_id', [$scenario::A, $scenario::B])->count())->toBe(2)
            ->and(DB::table(MediaTables::OwnerSlotOperations)->where('idempotency_key', $key)
                ->distinct()->count('request_hash'))->toBe(2);
    } finally {
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status, WNOHANG);
        }
        foreach ([$gate, ...$results, ...$ready] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
});
