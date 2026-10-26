<?php

declare(strict_types=1);
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Contracts\MediaHostResolver;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Ownership\MediaOwnerCatalog;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaMultipart;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Services\MediaUploader;
use Nvl\Media\Services\PortableMediaSearchDriver;
use Nvl\Media\Services\SystemMediaHostResolver;
use Nvl\Media\Services\UnsupportedMultipartUploadGateway;
use Nvl\Media\Tests\MediaTestCase;
use Nvl\Support\Enums\SystemActor;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(MediaTestCase::class);
}
/** @return list<array{class-string, class-string}> */
function nvlConsumerBindingsForMedia(): array
{
    return [
        [MediaCatalogImport::class, MediaOwnerCatalog::class],
        [MediaHostResolver::class, SystemMediaHostResolver::class],
        [MediaSearchDriver::class, PortableMediaSearchDriver::class],
        [MultipartUploadGateway::class, UnsupportedMultipartUploadGateway::class],
    ];
}
test('published workflows expose native explicit actor signatures and document array boundaries', function (): void {
    $workflows = [MediaUploader::class => ['upload', 'replace'], MediaAttachments::class => ['attach', 'detach', 'replaceSlot', 'clearSlot', 'copySlot'], MediaLibrary::class => ['rename', 'updateMetadata', 'relocate', 'delete', 'bulkDelete', 'tag'], MediaReader::class => ['get', 'paginate', 'slots'], MediaMultipart::class => ['initiate', 'signPart', 'complete', 'abort']];
    foreach ($workflows as $class => $methods) {
        foreach ($methods as $name) {
            $method = new ReflectionMethod($class, $name);
            expect($method->isPublic())->toBeTrue()->and($method->isStatic())->toBeFalse()->and($method->getReturnType())->not->toBeNull();
            $actors = array_filter($method->getParameters(), static fn (ReflectionParameter $parameter): bool => str_contains((string) $parameter->getType(), Authenticatable::class));
            expect($actors)->toHaveCount(1);
            foreach ($actors as $actor) {
                expect($actor->isOptional())->toBeFalse()->and((string) $actor->getType())->toContain(SystemActor::class);
            }
            foreach ($method->getParameters() as $parameter) {
                expect((string) $parameter->getType())->not->toContain('\\Data\\', 'Illuminate\\Http\\Request');
                if ((string) $parameter->getType() === 'array') {
                    expect($method->getDocComment())->toContain('$'.$parameter->getName());
                }
            }
        }
    }
});
test('native provider defaults resolve each workflow while preserving late host substitutes', function (): void {
    foreach (nvlConsumerBindingsForMedia() as [$contract, $implementation]) {
        expect($this->app->bound($contract))->toBeTrue()->and($this->app->make($contract))->toBeInstanceOf($implementation);
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
