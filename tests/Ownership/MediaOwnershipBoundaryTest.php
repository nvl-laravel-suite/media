<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMedia;
use Nvl\Media\Tests\Fixtures\OwnershipMediaOwner;
use Nvl\Media\Tests\Fixtures\OwnershipMediaScenario;
use Nvl\Media\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);

it('separates identical private uploads and rejects foreign attachment and delivery', function (): void {
    Storage::fake('public');
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $foreign = null;
    foreach ([7, 8] as $id) {
        $runner->run(OwnershipCompany::query()->findOrFail($id), $actor, function () use ($id, &$foreign): void {
            $owner = app(OwnershipWriter::class)->create(OwnershipMediaOwner::class, fn (OwnershipAssignment $a) => OwnershipMediaOwner::query()->forceCreate([...$a->attributes(), 'name' => 'Host owner']));
            $media = app(UploadMediaAction::class)->execute(UploadedFile::fake()->createWithContent('secret.txt', 'same private bytes'), 'public', $owner, new MediaSlot('documents'), 'secret.txt', skipAutoVariations: true, uploadedBy: '1');
            expect($media::class)->toBe(OwnershipMedia::class)
                ->and(MediaModelRegistry::query()->count())->toBe(1)
                ->and($media->storage_path)->toContain('owners/');
            app(AttachMediaAction::class)->execute($media, $owner, dispatchVariations: false);
            if ($id === 7) {
                $foreign = $media;
            } else {
                expect($media->id)->not->toBe($foreign->id)
                    ->and($media->storage_path)->not->toBe($foreign->storage_path);
                expect(fn () => app(AttachMediaAction::class)->execute($foreign, $owner, dispatchVariations: false))->toThrow(OwnershipViolation::class);
                expect(fn () => $foreign->getUrl())->toThrow(OwnershipViolation::class);
            }
        });
    }
});

it('attaches directly to the admitted native company without an extra owned domain model', function (): void {
    Storage::fake('public');
    $company = OwnershipCompany::query()->findOrFail(7);
    $actor = OwnershipActor::query()->firstOrFail();
    app(OwnershipRunner::class)->run($company, $actor, function () use ($company, $actor): void {
        $media = $company->nvlMedia()->add(UploadedFile::fake()->createWithContent('company.txt', 'company bytes'))->uploadedBy($actor)->slot('documents');
        expect($media::class)->toBe(OwnershipMedia::class)->and($media->associations()->count())->toBe(1);
        expect(fn () => app(AttachMediaAction::class)->execute($media, OwnershipCompany::query()->findOrFail(8), dispatchVariations: false))->toThrow(OwnershipViolation::class);
    });
});

it('guards native association targets during quiet creation and reassignment', function (): void {
    Storage::fake('public');
    app(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail(7), OwnershipActor::query()->firstOrFail(), function (): void {
        $media = OwnershipMediaScenario::upload(OwnershipMediaScenario::owner());
        $association = app(AttachMediaAction::class)->execute($media, OwnershipCompany::query()->findOrFail(7), dispatchVariations: false);
        $association->associable()->associate(OwnershipCompany::query()->findOrFail(8));
        expect(fn () => $association->saveQuietly())->toThrow(OwnershipViolation::class);
        $foreign = new MediaAssociation(['media_id' => $media->id, 'associable_type' => (new OwnershipCompany)->getMorphClass(), 'associable_id' => '8', 'collection' => 'documents']);
        expect(fn () => app(OwnershipWriter::class)->create($foreign::class, function (OwnershipAssignment $assignment) use ($foreign) {
            $foreign->forceFill($assignment->attributes())->saveQuietly();

            return $foreign;
        }))->toThrow(OwnershipViolation::class);
        expect(fn () => MediaAssociation::query()->whereKey($association->id)->update(['associable_id' => '8']))->toThrow(QueryException::class);
    });
});
