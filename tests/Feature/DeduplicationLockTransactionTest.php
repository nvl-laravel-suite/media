<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDeduplicationLock;

/** Probe the lock the way another request would: through its own lock service instance. */
function deduplicationLockIsFree(): bool
{
    try {
        app()->build(MediaDeduplicationLock::class)->execute('digest', 'public', true, null, null, static fn (): Media => new Media);

        return true;
    } catch (MediaUploadException) {
        return false;
    }
}

beforeEach(function (): void {
    config(['nvl-media.deduplication_lock.wait_seconds' => 0]);
});

it('holds the deduplication lock until the host transaction commits', function (): void {
    $heldInsideTransaction = null;

    DB::transaction(function () use (&$heldInsideTransaction): void {
        app(MediaDeduplicationLock::class)->execute('digest', 'public', true, null, null, static fn (): Media => new Media);
        // The row this upload inserted is still invisible to other connections, so an identical upload must wait.
        $heldInsideTransaction = ! deduplicationLockIsFree();
    });

    expect($heldInsideTransaction)->toBeTrue()->and(deduplicationLockIsFree())->toBeTrue();
});

it('releases the deduplication lock when the host transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            app(MediaDeduplicationLock::class)->execute('digest', 'public', true, null, null, static fn (): Media => new Media);

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
        // The rollback is the scenario under test.
    }

    expect(deduplicationLockIsFree())->toBeTrue();
});

it('releases the deduplication lock immediately outside a transaction, including after a failure', function (): void {
    expect(deduplicationLockIsFree())->toBeTrue()->and(deduplicationLockIsFree())->toBeTrue();
    expect(fn () => app(MediaDeduplicationLock::class)->execute('digest', 'public', true, null, null, static fn (): Media => throw new RuntimeException('upload failure')))->toThrow(RuntimeException::class);
    expect(deduplicationLockIsFree())->toBeTrue();
});

it('lets the same request repeat an identical upload inside its open transaction', function (): void {
    $runs = 0;

    DB::transaction(function () use (&$runs): void {
        foreach ([1, 2] as $attempt) {
            app(MediaDeduplicationLock::class)->execute('digest', 'public', true, null, null, static function () use (&$runs): Media {
                $runs++;

                return new Media;
            });
        }
    });

    expect($runs)->toBe(2)->and(deduplicationLockIsFree())->toBeTrue();
});
