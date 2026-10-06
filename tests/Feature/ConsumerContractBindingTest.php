<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Media\Actions\AbortMultipartUploadAction;
use Nvl\Media\Actions\AdoptSpatieMediaAction;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\BulkDeleteMediaAction;
use Nvl\Media\Actions\BulkMoveMediaAction;
use Nvl\Media\Actions\BulkTagMediaAction;
use Nvl\Media\Actions\ClearOwnerMediaSlotAction;
use Nvl\Media\Actions\CompleteMultipartUploadAction;
use Nvl\Media\Actions\CopyOwnerMediaSlotAction;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\DetachMediaAction;
use Nvl\Media\Actions\FinalizeMediaScanAction;
use Nvl\Media\Actions\GenerateImageVariationAction;
use Nvl\Media\Actions\GetOwnerMediaSlotAction;
use Nvl\Media\Actions\GrantMediaToTenantAction;
use Nvl\Media\Actions\ImportPlatformMediaAction;
use Nvl\Media\Actions\InitiateMultipartUploadAction;
use Nvl\Media\Actions\ListOwnerMediaSlotsAction;
use Nvl\Media\Actions\MutateMediaTagsAction;
use Nvl\Media\Actions\RelocateMediaAction;
use Nvl\Media\Actions\RenameMediaAction;
use Nvl\Media\Actions\ReplaceMediaFileAction;
use Nvl\Media\Actions\ReplaceOwnerMediaSlotAction;
use Nvl\Media\Actions\ReusePublicMediaAction;
use Nvl\Media\Actions\RevokeMediaTenantGrantAction;
use Nvl\Media\Actions\SignMultipartPartAction;
use Nvl\Media\Actions\UpdateMediaMetadataAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Contracts\AbortMultipartUploadContract;
use Nvl\Media\Contracts\AdoptSpatieMediaContract;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\BulkDeleteMediaContract;
use Nvl\Media\Contracts\BulkMoveMediaContract;
use Nvl\Media\Contracts\BulkTagMediaContract;
use Nvl\Media\Contracts\ClearOwnerMediaSlotContract;
use Nvl\Media\Contracts\CompleteMultipartUploadContract;
use Nvl\Media\Contracts\CopyOwnerMediaSlotContract;
use Nvl\Media\Contracts\DeleteMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\FinalizeMediaScanContract;
use Nvl\Media\Contracts\GenerateImageVariationContract;
use Nvl\Media\Contracts\GetOwnerMediaSlotContract;
use Nvl\Media\Contracts\GrantMediaToTenantContract;
use Nvl\Media\Contracts\ImportPlatformMediaContract;
use Nvl\Media\Contracts\InitiateMultipartUploadContract;
use Nvl\Media\Contracts\ListOwnerMediaSlotsContract;
use Nvl\Media\Contracts\MutateMediaTagsContract;
use Nvl\Media\Contracts\RelocateMediaContract;
use Nvl\Media\Contracts\RenameMediaContract;
use Nvl\Media\Contracts\ReplaceMediaFileContract;
use Nvl\Media\Contracts\ReplaceOwnerMediaSlotContract;
use Nvl\Media\Contracts\ReusePublicMediaContract;
use Nvl\Media\Contracts\RevokeMediaTenantGrantContract;
use Nvl\Media\Contracts\SignMultipartPartContract;
use Nvl\Media\Contracts\UpdateMediaMetadataContract;
use Nvl\Media\Contracts\UploadMediaContract;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Tests\MediaTestCase;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(MediaTestCase::class);
}

/** @return list<array{class-string, class-string}> */
function nvlConsumerBindingsForMedia(): array
{
    return [
        [AbortMultipartUploadContract::class, AbortMultipartUploadAction::class],
        [AdoptSpatieMediaContract::class, AdoptSpatieMediaAction::class],
        [AttachMediaContract::class, AttachMediaAction::class],
        [BulkDeleteMediaContract::class, BulkDeleteMediaAction::class],
        [BulkMoveMediaContract::class, BulkMoveMediaAction::class],
        [BulkTagMediaContract::class, BulkTagMediaAction::class],
        [ClearOwnerMediaSlotContract::class, ClearOwnerMediaSlotAction::class],
        [CompleteMultipartUploadContract::class, CompleteMultipartUploadAction::class],
        [CopyOwnerMediaSlotContract::class, CopyOwnerMediaSlotAction::class],
        [DeleteMediaContract::class, DeleteMediaAction::class],
        [DetachMediaContract::class, DetachMediaAction::class],
        [FinalizeMediaScanContract::class, FinalizeMediaScanAction::class],
        [GenerateImageVariationContract::class, GenerateImageVariationAction::class],
        [GetOwnerMediaSlotContract::class, GetOwnerMediaSlotAction::class],
        [GrantMediaToTenantContract::class, GrantMediaToTenantAction::class],
        [ImportPlatformMediaContract::class, ImportPlatformMediaAction::class],
        [InitiateMultipartUploadContract::class, InitiateMultipartUploadAction::class],
        [ListOwnerMediaSlotsContract::class, ListOwnerMediaSlotsAction::class],
        [MutateMediaTagsContract::class, MutateMediaTagsAction::class],
        [RelocateMediaContract::class, RelocateMediaAction::class],
        [RenameMediaContract::class, RenameMediaAction::class],
        [ReplaceMediaFileContract::class, ReplaceMediaFileAction::class],
        [ReplaceOwnerMediaSlotContract::class, ReplaceOwnerMediaSlotAction::class],
        [ReusePublicMediaContract::class, ReusePublicMediaAction::class],
        [RevokeMediaTenantGrantContract::class, RevokeMediaTenantGrantAction::class],
        [SignMultipartPartContract::class, SignMultipartPartAction::class],
        [UpdateMediaMetadataContract::class, UpdateMediaMetadataAction::class],
        [UploadMediaContract::class, UploadMediaAction::class],
    ];
}

test('published workflow contracts retain native signatures attributes and generic documentation', function (): void {
    $genericDocumentation = static function (string|false $documentation): array {
        if ($documentation === false) {
            return [];
        }
        preg_match_all('/@param\s+([^\r\n]+?)\s+(\$[A-Za-z_][A-Za-z0-9_]*)\b/', $documentation, $parameters, PREG_SET_ORDER);
        $result = [];
        foreach ($parameters as $parameter) {
            $type = preg_replace('/\s+/', '', $parameter[1]);
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@param'.$parameter[2]] = $type;
            }
        }
        if (preg_match('/@return\s+([^\r\n]+)/', $documentation, $return) === 1) {
            $type = '';
            $depth = 0;
            foreach (str_split($return[1]) as $character) {
                if (preg_match('/\s/', $character) === 1 && $depth === 0) {
                    break;
                }
                if (str_contains('<{([', $character)) {
                    $depth++;
                } elseif (str_contains('>})]', $character)) {
                    $depth--;
                }
                if (preg_match('/\s/', $character) !== 1) {
                    $type .= $character;
                }
            }
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@return'] = $type;
            }
        }

        return $result;
    };

    foreach (nvlConsumerBindingsForMedia() as [$contract, $implementation]) {
        $interface = new ReflectionClass($contract);
        $concrete = new ReflectionClass($implementation);
        expect($interface->isInterface())->toBeTrue()
            ->and($concrete->implementsInterface($contract))->toBeTrue();
        foreach ($interface->getMethods() as $method) {
            $native = $concrete->getMethod($method->getName());
            $return = (string) $method->getReturnType();
            if ($contract !== AttachMediaContract::class) {
                $publishedTypes = $genericDocumentation($method->getDocComment());
                foreach ($genericDocumentation($native->getDocComment()) as $tag => $type) {
                    expect($publishedTypes[$tag] ?? null)->toBe($type);
                }
            }
            expect($native->isPublic())->toBeTrue()
                ->and($native->isStatic())->toBeFalse()
                ->and(count($method->getParameters()))->toBe($contract === AttachMediaContract::class ? count($native->getParameters()) - 1 : count($native->getParameters()));
            if ($return !== 'self') {
                expect((string) $native->getReturnType())->toBe($return);
            } else {
                $nativeReturn = (string) $native->getReturnType();
                expect(is_a(in_array($nativeReturn, ['self', 'static'], true) ? $native->getDeclaringClass()->getName() : $nativeReturn, $contract, true))->toBeTrue();
            }
            foreach ($method->getParameters() as $position => $parameter) {
                $actual = $native->getParameters()[$position];
                expect($actual->getName())->toBe($parameter->getName())
                    ->and((string) $actual->getType())->toBe((string) $parameter->getType())
                    ->and($actual->isVariadic())->toBe($parameter->isVariadic())
                    ->and($actual->isPassedByReference())->toBe($parameter->isPassedByReference())
                    ->and($actual->isDefaultValueAvailable())->toBe($parameter->isDefaultValueAvailable())
                    ->and(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $actual->getAttributes()))
                    ->toBe(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $parameter->getAttributes()));
                if ($parameter->isDefaultValueAvailable()) {
                    expect($actual->getDefaultValue())->toEqual($parameter->getDefaultValue());
                }
            }
        }
    }
});

test('native provider defaults resolve each workflow while preserving late host substitutes', function (): void {
    foreach (nvlConsumerBindingsForMedia() as [$contract, $implementation]) {
        expect($this->app->bound($contract))->toBeTrue()
            ->and($this->app->make($contract))->toBeInstanceOf($implementation);
        $host = Mockery::mock($contract);
        $this->app->instance($contract, $host);
        expect($this->app->make($contract))->toBe($host);
    }
});

test('provider registration preserves early interface bindings in a second native application', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $hosts = [];
    foreach (nvlConsumerBindingsForMedia() as [$contract]) {
        $hosts[$contract] = Mockery::mock($contract);
        $consumer->instance($contract, $hosts[$contract]);
    }
    try {
        $consumer->register(MediaServiceProvider::class);
        foreach ($hosts as $contract => $host) {
            expect($consumer->make($contract))->toBe($host);
        }
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
