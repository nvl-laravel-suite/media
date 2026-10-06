<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Contracts\MediaLibraryContract;
use Nvl\Media\Contracts\MediaUploadBuilder;
use Nvl\Media\Facades\Media as MediaFacade;
use Nvl\Media\Models\Media;
use Nvl\Media\Testing\FakeMediaLibrary;
use Nvl\Media\Testing\FakeMediaUploadBuilder;
use Nvl\Support\Testing\FakeExpectationFailed;
use Nvl\Support\Testing\UnscriptedFakeCall;

beforeEach(function (): void {
    $this->owner = Mockery::mock(Model::class, HasNvlMedia::class);
    $this->owner->shouldReceive('getKey')->andReturn(17);
    $this->owner->shouldReceive('getMorphClass')->andReturn('article');
    $this->media = Mockery::mock(Media::class);
});

afterEach(function (): void {
    Mockery::close();
    Facade::clearResolvedInstances();
});

it('installs a separate fake in its container and clears a previously resolved facade', function (): void {
    $original = Facade::getFacadeApplication();
    $first = new Container;
    $first->instance('config', new Repository(['filesystems' => ['cloud' => 'tenant-cloud']]));
    Facade::setFacadeApplication($first);
    $old = new FakeMediaLibrary;
    $first->instance(MediaLibraryContract::class, $old);
    expect(MediaFacade::getFacadeRoot())->toBe($old);
    $fake = FakeMediaLibrary::fake($first);
    $builder = new FakeMediaUploadBuilder;
    $fake->willReturn('copy', $builder);
    $builder->willReturn('slotOnCloudDisk', $this->media);
    MediaFacade::copy($this->owner, '/this/file/must/not/be/read')->slotOnCloudDisk('');
    $request = $builder->calls()[0]->arguments;
    expect(MediaFacade::getFacadeRoot())->toBe($fake)
        ->and($request['options']['disk'])->toBe('tenant-cloud')
        ->and($request['options']['preserveOriginal'])->toBeTrue()
        ->and($request['options']['slot'])->toBe('');
    $second = new Container;
    expect(FakeMediaLibrary::fake($second))->not->toBe($fake)
        ->and($first->make(MediaLibraryContract::class))->toBe($fake);
    Facade::setFacadeApplication($original);
});

it('records complete immutable fluent request snapshots without executing sources or closures', function (): void {
    $fake = new FakeMediaLibrary;
    $builder = new FakeMediaUploadBuilder;
    $sanitizer = fn () => throw new RuntimeException('sanitizer must stay inert');
    $fake->willReturn('fromUrl', $builder);
    $builder->willReturn('slot', $this->media)->willReturn('upload', $this->media);
    $result = $fake->fromUrl($this->owner, 'https://unreachable.invalid/private', 'image/png', 'image/jpeg')
        ->usingSlot('cover')->usingFileName('photo.png')->sanitizingFileName($sanitizer)
        ->withCustomProperties([8 => 'first', 'same' => 1])->withCustomProperties([9 => 'second', 'same' => 2])
        ->withTags(['first'])->tags('second', 'first')->toLocale('bg')->withOrder(4)
        ->toDisk('private')->toFolder('photos')->asPublic(false)->preservingOriginal()
        ->withoutVariations()->withVariations(['thumb' => ['width' => 120], 5 => ['width' => 240]])
        ->withAssociationMeta(['role' => 'hero', 'slot' => 'ignored'])->allowingDuplicates()->toCollection('gallery')
        ->slot('', 'archive');
    $builder->tags('later')->upload();
    $first = $builder->calls()[0]->arguments;
    $second = $builder->calls()[1]->arguments;
    expect($result)->toBe($this->media)
        ->and($first['source'])->toBe(['method' => 'fromUrl', 'arguments' => ['url' => 'https://unreachable.invalid/private', 'allowedMimeTypes' => ['image/png', 'image/jpeg']]])
        ->and($first['owner'])->toBe(['model' => $this->owner, 'id' => 17, 'type' => 'article'])
        ->and($first['options'])->toBe(['slot' => '', 'pendingSlot' => 'cover', 'collection' => 'gallery', 'fileName' => 'photo.png', 'fileNameSanitizer' => $sanitizer, 'preserveOriginal' => true, 'customProperties' => [0 => 'first', 'same' => 2, 1 => 'second'], 'tags' => ['first', 'second', 'first'], 'locale' => 'bg', 'order' => 4, 'disk' => 'archive', 'folder' => 'photos', 'public' => false, 'variations' => ['thumb' => ['width' => 120], 5 => ['width' => 240]], 'skipVariations' => true, 'associationMeta' => ['role' => 'hero', 'slot' => ''], 'allowDuplicates' => true, 'uploaderId' => null, 'uploaderType' => null])
        ->and($second['options']['tags'])->toBe(['first', 'second', 'first', 'later'])
        ->and($second['options']['slot'])->toBe('cover');
});

it('records one attempt before throws and exhaustion with independent terminal FIFO queues', function (): void {
    $fake = new FakeMediaLibrary;
    $builder = new FakeMediaUploadBuilder;
    $failure = new RuntimeException('scripted');
    $fake->willReturn('fromBase64', $builder);
    $builder->willThrow('upload', $failure)->willReturn('upload', $this->media)->willReturn('slot', $this->media);
    $fake->fromBase64($this->owner, 'not even base64')->usingSlot('default');
    try {
        $builder->upload();
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect($builder->slot())->toBe($this->media)->and($builder->upload())->toBe($this->media);
    expect(fn () => $builder->upload())->toThrow(UnscriptedFakeCall::class);
    expect(array_column($builder->calls(), 'method'))->toBe(['upload', 'slot', 'upload', 'upload']);
    $builder->assertCalled('upload', times: 3);
});

it('rejects reused fake builders without corrupting the first captured owner and source', function (): void {
    $fake = new FakeMediaLibrary;
    $builder = new FakeMediaUploadBuilder;
    $fake->willReturn('fromDisk', $builder)->willReturn('copy', $builder);
    $builder->willReturn('upload', $this->media);
    $fake->fromDisk($this->owner, 'first', 'source');
    expect(fn () => $fake->copy($this->owner, 'second'))->toThrow(FakeExpectationFailed::class);
    $builder->upload();
    expect($builder->calls()[0]->arguments['source'])->toBe(['method' => 'fromDisk', 'arguments' => ['key' => 'first', 'disk' => 'source']])
        ->and($builder->calls()[0]->arguments['options']['preserveOriginal'])->toBeFalse()
        ->and($fake->calls())->toHaveCount(2);
});

it('returns arbitrary host builders unchanged and rejects invalid native return values', function (): void {
    $fake = new FakeMediaLibrary;
    $host = Mockery::mock(MediaUploadBuilder::class);
    $fake->willReturn('fromString', $host)->willReturn('fromString', 'invalid')->willReturn('findOrFail', false)->willReturn('delete', 'invalid');
    expect($fake->fromString($this->owner, 'source'))->toBe($host);
    expect(fn () => $fake->fromString($this->owner, 'source'))->toThrow(TypeError::class);
    expect(fn () => $fake->findOrFail('missing'))->toThrow(TypeError::class);
    expect(fn () => $fake->delete('missing'))->toThrow(TypeError::class);
    expect($fake->calls())->toHaveCount(4);
});

it('does not invent nullable returns or side effects and rejects unknown method scripts', function (): void {
    $fake = new FakeMediaLibrary;
    expect(fn () => $fake->urlIfExists(null))->toThrow(UnscriptedFakeCall::class);
    $fake->willReturn('urlIfExists', null)->willReturn('detach', 0)->willReturn('delete', false);
    expect($fake->urlIfExists(null))->toBeNull()->and($fake->detach('id', $this->owner))->toBe(0)->and($fake->delete('id'))->toBeFalse();
    expect(fn () => $fake->willReturn('imaginaryUpload', null))->toThrow(FakeExpectationFailed::class);
    expect(fn () => $fake->assertCalled('delete', times: -1))->toThrow(FakeExpectationFailed::class);
});

it('captures every source entry point with its actual native argument keys', function (string $method, array $arguments, array $source): void {
    $fake = new FakeMediaLibrary;
    $builder = new FakeMediaUploadBuilder;
    $fake->willReturn($method, $builder);
    $builder->willReturn('upload', $this->media);
    $fake->{$method}($this->owner, ...$arguments)->upload();
    expect($fake->calls()[0]->method)->toBe($method)
        ->and($fake->calls()[0]->arguments['owner'])->toBe($this->owner)
        ->and($builder->calls()[0]->arguments['source'])->toBe(['method' => $method, 'arguments' => $source]);
})->with([
    ['add', ['/missing', 'hero'], ['file' => '/missing', 'slot' => 'hero']],
    ['copy', ['/missing'], ['file' => '/missing']],
    ['fromRequest', ['missing.input'], ['key' => 'missing.input']],
    ['fromUrl', ['https://invalid.test', 'image/png'], ['url' => 'https://invalid.test', 'allowedMimeTypes' => ['image/png']]],
    ['fromBase64', ['invalid-base64', 'image/png'], ['payload' => 'invalid-base64', 'allowedMimeTypes' => ['image/png']]],
    ['fromString', ["binary\0content"], ['contents' => "binary\0content"]],
    ['fromBinary', ["binary\0content", 'file.dat', 'application/octet-stream'], ['contents' => "binary\0content", 'filename' => 'file.dat', 'allowedMimeTypes' => ['application/octet-stream']]],
    ['fromDisk', ['key', 'absent-disk'], ['key' => 'key', 'disk' => 'absent-disk']],
]);

it('records every other native library operation and returns only its typed scripted result without resolving collaborators', function (): void {
    $fake = new FakeMediaLibrary;
    $valueFor = function (ReflectionType $type) use (&$valueFor): mixed {
        if ($type instanceof ReflectionUnionType) {
            return $valueFor($type->getTypes()[0]);
        }
        if ($type instanceof ReflectionIntersectionType) {
            return $this->owner;
        }
        $name = $type->getName();
        if ($name === 'string') {
            return 'explicit';
        }
        if ($name === 'int') {
            return 4;
        }
        if ($name === 'bool') {
            return true;
        }
        if ($name === 'array') {
            return [];
        }
        if ($name === 'void' || $name === 'null') {
            return null;
        }
        if (enum_exists($name)) {
            return $name::cases()[0];
        }
        if (interface_exists($name) || is_a($name, Model::class, true)) {
            return Mockery::mock($name);
        }

        return (new ReflectionClass($name))->newInstanceWithoutConstructor();
    };
    $count = 0;
    foreach ((new ReflectionClass(MediaLibraryContract::class))->getMethods() as $method) {
        if ($method->getReturnType()->getName() === MediaUploadBuilder::class) {
            continue;
        }
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $arguments[$parameter->name] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : $valueFor($parameter->getType());
        }
        $result = $valueFor($method->getReturnType());
        $fake->willReturn($method->name, $result);
        expect($fake->{$method->name}(...$arguments))->toBe($result)
            ->and($fake->calls($method->name)[0]->arguments)->toBe($arguments);
        $count++;
    }
    expect($count)->toBe(19)->and($fake->calls())->toHaveCount(19);
});

it('uses native uploader identity and rejects invalid identifiers before terminal effects', function (): void {
    $uploader = Mockery::mock(Model::class, Authenticatable::class);
    $uploader->shouldReceive('getAuthIdentifier')->andReturn(42, []);
    $uploader->shouldReceive('getMorphClass')->once()->andReturn('member');
    $fake = new FakeMediaLibrary;
    $builder = new FakeMediaUploadBuilder;
    $fake->willReturn('add', $builder);
    $builder->willReturn('upload', $this->media);
    $fake->add($this->owner, '/missing')->uploadedBy($uploader)->upload();
    expect($builder->calls()[0]->arguments['options']['uploaderId'])->toBe('42')
        ->and($builder->calls()[0]->arguments['options']['uploaderType'])->toBe('member');
    expect(fn () => $builder->uploadedBy($uploader))->toThrow(InvalidArgumentException::class);
    expect($builder->calls())->toHaveCount(1);
});
