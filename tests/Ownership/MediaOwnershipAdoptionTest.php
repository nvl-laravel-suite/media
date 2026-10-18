<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\HistoricalOwnershipMediaTestCase;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(HistoricalOwnershipMediaTestCase::class);

/** Create actual historical bytes and records before any ownership assignment. */
function historicalOwnershipMedia(string $path = 'media/old.txt'): string
{
    $id = (string) Str::uuid();
    Storage::disk('public')->put($path, 'reviewed historical bytes');
    DB::table(MediaTables::get(MediaTables::Media))->insert([
        'id' => $id, 'filename' => 'old.txt', 'hash' => basename($path), 'extension' => 'txt',
        'mime_type' => 'text/plain', 'size' => strlen('reviewed historical bytes'),
        'disk' => 'public', 'folder' => '', 'type' => 'document',
        'digest' => hash('sha256', 'reviewed historical bytes'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('backfills reviewed existing bytes in bounded resumable pages and retains native identity', function (): void {
    Storage::fake('public');
    $id = historicalOwnershipMedia();
    $reference = app(OwnerIdentityResolver::class)->reference(OwnershipCompany::query()->findOrFail(7));
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('review-media', 'operator', '1');
    $plan = $coordinator->prepare(['media', 'fixture'], [new OwnershipMapping('media.assets', $id, $reference, ['expected_digest' => hash('sha256', 'reviewed historical bytes')])], $operation);
    expect($coordinator->backfill($plan, 1, $operation))->toBeFalse();
    $plan = $coordinator->resume($plan->id);
    while (! $coordinator->backfill($plan, 1, $operation)) {
    }
    expect($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    expect(DB::table(MediaTables::get(MediaTables::Media))->where('id', $id)->value('company_id'))->toBe(7);
});

it('blocks activation when reviewed historical bytes change', function (): void {
    Storage::fake('public');
    $id = historicalOwnershipMedia();
    $reference = app(OwnerIdentityResolver::class)->reference(OwnershipCompany::query()->findOrFail(7));
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('review-media', 'operator', '1');
    $plan = $coordinator->prepare(['media', 'fixture'], [new OwnershipMapping('media.assets', $id, $reference, ['expected_digest' => hash('sha256', 'reviewed historical bytes')])], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
    }
    Storage::disk('public')->put('media/old.txt', 'changed bytes');
    expect(fn () => $coordinator->activate($plan, $operation))->toThrow(OwnershipViolation::class);
});

it('rejects an association whose reviewed asset belongs to another canonical company', function (): void {
    Storage::fake('public');
    $id = historicalOwnershipMedia();
    $association = (string) Str::uuid();
    DB::table(MediaTables::get(MediaTables::Associations))->insert(['id' => $association, 'media_id' => $id, 'associable_type' => (new OwnershipCompany)->getMorphClass(), 'associable_id' => '8', 'collection' => 'documents', 'created_at' => now(), 'updated_at' => now()]);
    $reference = app(OwnerIdentityResolver::class)->reference(OwnershipCompany::query()->findOrFail(7));
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('review-media', 'operator', '1');
    $plan = $coordinator->prepare(['media', 'fixture'], [new OwnershipMapping('media.assets', $id, $reference, ['expected_digest' => hash('sha256', 'reviewed historical bytes')]), new OwnershipMapping('media.associations', $association, $reference)], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
    }
    expect(fn () => $coordinator->activate($plan, $operation))->toThrow(OwnershipViolation::class);
});

it('refuses historical files shared by different reviewed owners', function (): void {
    Storage::fake('public');
    $first = historicalOwnershipMedia();
    $second = historicalOwnershipMedia();
    $identities = app(OwnerIdentityResolver::class);
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('review-media', 'operator', '1');
    $plan = $coordinator->prepare(['media', 'fixture'], [
        new OwnershipMapping('media.assets', $first, $identities->reference(OwnershipCompany::query()->findOrFail(7)), ['expected_digest' => hash('sha256', 'reviewed historical bytes')]),
        new OwnershipMapping('media.assets', $second, $identities->reference(OwnershipCompany::query()->findOrFail(8)), ['expected_digest' => hash('sha256', 'reviewed historical bytes')]),
    ], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
    }
    expect(fn () => $coordinator->activate($plan, $operation))->toThrow(OwnershipViolation::class);
});
