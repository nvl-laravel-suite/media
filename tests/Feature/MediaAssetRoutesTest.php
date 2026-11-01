<?php

declare(strict_types=1);
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Nvl\Media\Conversions\ConversionDefinition;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Media\Services\MediaVariations;
use Nvl\Support\Enums\SystemActor;

function createAssetUser(array $overrides = []): User
{
    return User::withoutEvents(static fn (): User => User::forceCreate(array_merge(['name' => 'Asset User', 'email' => fake()->unique()->safeEmail(), 'password' => 'yIXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'], $overrides)));
}
function createAssetMedia(array $overrides = []): Media
{
    if (isset($overrides['uploaded_by'])) {
        $overrides += ['uploaded_by_type' => User::class];
    }

    return Media::create(array_merge(['filename' => 'asset.jpg', 'hash' => 'asset-hash.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1024, 'disk' => 'public', 'folder' => 'assets', 'is_public' => true, 'type' => MediaType::IMAGE, 'digest' => md5('asset')], $overrides));
}
function createTinyJpegBinary(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($image, null, 90);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return $binary;
}
describe('public media asset route', function () {
    beforeEach(function () {
        Storage::fake('public');
    });
    it('keeps asset delivery outside the web middleware stack', function () {
        $route = Route::getRoutes()->getByName('nvl.media.assets.show');
        expect($route)->not->toBeNull();
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain(SubstituteBindings::class)->not->toContain('web')->not->toContain(StartSession::class);
    });
    it('serves a public asset through centralized route', function () {
        $media = createAssetMedia(['is_public' => true]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $response = $this->get("/nvl/media/assets/{$media->id}");
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        expect($response->headers->getCookies())->toBeEmpty()->and((string) $response->headers->get('Cache-Control'))->toContain('max-age=0')->toContain('must-revalidate')->not->toContain('immutable')->and((string) $response->headers->get('Vary'))->not->toContain('X-Inertia');
    });
    it('uses configurable immutable cache-control only for versioned public assets', function () {
        config(['nvl-media.assets.public_cache_control' => 'public, max-age=600']);
        config(['nvl-media.delivery.disks.public.public.mode' => 'application']);
        $media = createAssetMedia(['is_public' => true, 'hash' => 'public-cache.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $response = $this->get($media->buildPublicUrl());
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $response->assertOk()->assertHeader('Cache-Control');
        expect($cacheControl)->toContain('public')->toContain('max-age=600');
    });
    it('returns 404 when trying to access a private media through public route', function () {
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}")->assertNotFound();
    });
    it('returns 404 when the source file is missing from disk', function () {
        $media = createAssetMedia(['is_public' => true, 'hash' => 'missing-file.jpg']);
        $this->get("/nvl/media/assets/{$media->id}")->assertNotFound();
    });
    it('does not let the configured allowlist enable unsupported transformation queries', function () {
        config(['nvl-media.assets.allowed_parameters' => ['v', 'w']]);
        $media = createAssetMedia(['is_public' => true, 'hash' => 'allowed-params.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}?w=320")->assertUnprocessable();
    });
    it('rejects unknown public asset query parameters', function () {
        $media = createAssetMedia(['is_public' => true, 'hash' => 'unknown-param.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}?unexpected=value")->assertUnprocessable();
    });
    it('rejects named variations when configuration narrows the allowlist', function () {
        config(['nvl-media.assets.allowed_parameters' => []]);
        $media = createAssetMedia(['is_public' => true, 'hash' => 'disabled-variation.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}?v=thumb")->assertUnprocessable();
    });
    it('accepts the generated current public asset version', function () {
        config(['nvl-media.delivery.disks.public.public.mode' => 'application']);
        $media = createAssetMedia(['is_public' => true, 'hash' => 'versioned-param.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = $media->buildPublicUrl();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($query['version'] ?? null)->toBeString()->toHaveLength(16);
        $this->get($url)->assertOk();
    });
    it('rejects a fabricated public asset version', function () {
        config(['nvl-media.delivery.disks.public.public.mode' => 'application']);
        $media = createAssetMedia(['is_public' => true, 'hash' => 'fabricated-version.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = $media->buildPublicUrl();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $currentVersion = (string) ($query['version'] ?? '');
        $fabricatedVersion = ($currentVersion[0] ?? '') === 'a' ? 'b'.substr($currentVersion, 1) : 'a'.substr($currentVersion, 1);
        $this->get("/nvl/media/assets/{$media->id}?version={$fabricatedVersion}")->assertNotFound();
    });
    it('streams a public asset from a remote-style disk', function () {
        Storage::fake('s3');
        config(['filesystems.disks.s3.driver' => 's3']);
        $media = createAssetMedia(['disk' => 's3', 'is_public' => true, 'hash' => 'remote-public.jpg']);
        Storage::disk('s3')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
    it('streams a public asset from the cloudflare-r2 disk through the route-backed contract', function () {
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);
        $media = createAssetMedia(['disk' => 'cloudflare-r2', 'is_public' => true, 'hash' => 'r2-public.jpg']);
        Storage::disk('cloudflare-r2')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
});
describe('private media asset route', function () {
    beforeEach(function () {
        Storage::fake('public');
    });
    it('requires a valid signature', function () {
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/private/{$owner->id}/{$media->id}")->assertForbidden();
    });
    it('validates private asset signatures before route model binding', function () {
        $route = Route::getRoutes()->getByName('nvl.media.private.show');
        expect($route)->not->toBeNull();
        $middleware = $route->gatherMiddleware();
        $signaturePosition = array_search('signed', $middleware, true);
        $bindingPosition = array_search(SubstituteBindings::class, $middleware, true);
        expect($signaturePosition)->not->toBeFalse()->and($bindingPosition)->not->toBeFalse()->and($signaturePosition)->toBeLessThan($bindingPosition);
        $this->get('/nvl/media/private/missing/00000000-0000-0000-0000-000000000000?signature=invalid')->assertForbidden();
    });
    it('serves private media when signature and owner match', function () {
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = URL::temporarySignedRoute('nvl.media.private.show', now()->addMinutes(5), ['owner' => $owner->id, 'media' => $media->id, 'revision' => $media->revision]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
    it('allows signed framework parameters when configuration disables variations', function () {
        config(['nvl-media.assets.allowed_parameters' => []]);
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id, 'hash' => 'signed-framework-params.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = URL::temporarySignedRoute('nvl.media.private.show', now()->addMinutes(5), ['owner' => $owner->id, 'media' => $media->id, 'revision' => $media->revision]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
    it('rejects unknown parameters even when covered by a valid private signature', function () {
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id, 'hash' => 'signed-unknown-param.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = URL::temporarySignedRoute('nvl.media.private.show', now()->addMinutes(5), ['owner' => $owner->id, 'media' => $media->id, 'revision' => $media->revision, 'unexpected' => 'value']);
        $this->get($url)->assertUnprocessable();
    });
    it('returns 404 when owner segment does not match the media owner', function () {
        $owner = createAssetUser();
        $other = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = URL::temporarySignedRoute('nvl.media.private.show', now()->addMinutes(5), ['owner' => $other->id, 'media' => $media->id, 'revision' => $media->revision]);
        $this->get($url)->assertNotFound();
    });
    it('honors an authorized bearer capability independently of the recipient login', function () {
        $owner = createAssetUser();
        $other = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = $media->buildPrivateUrl($owner, now()->addMinutes(5));
        $this->actingAs($other)->get($url)->assertOk();
    });
    it('keeps authenticated downloads protected despite removed legacy policy config', function () {
        config(['nvl-media.assets.enforce_policy_for_authenticated' => false]);
        $owner = createAssetUser();
        $other = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id, 'hash' => 'policy-toggle.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $url = $media->getUrl();
        $this->actingAs($other)->get($url)->assertForbidden();
    });
    it('streams private media from a remote-style disk when signature and owner match', function () {
        Storage::fake('s3');
        config(['filesystems.disks.s3.driver' => 's3']);
        $owner = createAssetUser();
        $media = createAssetMedia(['disk' => 's3', 'is_public' => false, 'uploaded_by' => $owner->id, 'hash' => 'remote-private.jpg']);
        Storage::disk('s3')->put($media->buildPath(), createTinyJpegBinary());
        $url = URL::temporarySignedRoute('nvl.media.private.show', now()->addMinutes(5), ['owner' => $owner->id, 'media' => $media->id, 'revision' => $media->revision]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
    it('streams private media from the cloudflare-r2 disk only through a matching signed route', function () {
        Storage::fake('cloudflare-r2');
        config(['filesystems.disks.cloudflare-r2.driver' => 's3']);
        $owner = createAssetUser();
        $media = createAssetMedia(['disk' => 'cloudflare-r2', 'is_public' => false, 'uploaded_by' => $owner->id, 'hash' => 'r2-private.jpg']);
        Storage::disk('cloudflare-r2')->put($media->buildPath(), createTinyJpegBinary());
        $url = URL::temporarySignedRoute('nvl.media.private.show', now()->addMinutes(5), ['owner' => $owner->id, 'media' => $media->id, 'revision' => $media->revision]);
        $this->get("/nvl/media/assets/{$media->id}")->assertNotFound();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
});
describe('asset variation parameters', function () {
    beforeEach(function () {
        Storage::fake('public');
    });
    it('rejects dynamic sizing parameters on the asset route', function () {
        $media = createAssetMedia(['is_public' => true, 'hash' => 'dynamic-source.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $this->get("/nvl/media/assets/{$media->id}?w=1&h=1&fit=crop&fmt=webp&q=85")->assertStatus(422);
        $count = MediaImageVariation::query()->where('media_id', $media->id)->count();
        expect($count)->toBe(0);
    });
    it('serves a pre-existing variation when the named variation matches its label', function () {
        $media = createAssetMedia(['is_public' => true, 'hash' => 'preexisting-var.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        // Pre-generate a named variation
        $action = app(MediaVariations::class);
        $definition = new ConversionDefinition('thumb');
        $definition->width(150)->height(150)->quality(80)->format('webp');
        $variation = $action->generate(auth()->user() ?? SystemActor::Background, $media, $definition);
        expect($variation)->toBeInstanceOf(MediaImageVariation::class);
        config(['nvl-media.delivery.disks.public.public.mode' => 'application']);
        $url = $variation->getUrl();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($query)->toMatchArray(['v' => 'thumb'])->and($query['version'] ?? null)->toBeString()->toHaveLength(16);
        $this->get($url)->assertOk();
    });
    it('returns 404 when a requested named variation does not exist', function () {
        $media = createAssetMedia(['is_public' => true, 'hash' => 'fallback-original.jpg']);
        Storage::disk('public')->put($media->buildPath(), createTinyJpegBinary());
        $response = $this->get("/nvl/media/assets/{$media->id}?v=missing");
        $response->assertNotFound();
        $count = MediaImageVariation::query()->where('media_id', $media->id)->count();
        expect($count)->toBe(0);
    });
});

describe('authenticated private media asset route', function (): void {
    beforeEach(fn () => Storage::fake('public'));

    it('requires authentication before resolving private media', function (): void {
        $this->getJson('/nvl/media/authenticated/00000000-0000-0000-0000-000000000000')->assertUnauthorized();
    });

    it('authorizes the requesting actor on every private download', function (): void {
        $owner = createAssetUser();
        $other = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), 'private bytes');
        $this->actingAs($other)->get($media->getUrl())->assertForbidden();
    });

    it('serves authorized private bytes without issuing or redirecting to a bearer URL', function (): void {
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), 'private bytes');
        $url = $media->getUrl();
        expect($url)->not->toContain('signature=')->not->toContain('expires=');
        $response = $this->actingAs($owner)->get($url);
        $response->assertOk()->assertHeaderMissing('Location');
        expect((string) $response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
        ob_start();
        $response->baseResponse->sendContent();
        expect(ob_get_clean())->toBe('private bytes');
    });

    it('rejects signed capabilities after a standalone asset revision changes', function (): void {
        $owner = createAssetUser();
        $media = createAssetMedia(['is_public' => false, 'uploaded_by' => $owner->id]);
        Storage::disk('public')->put($media->buildPath(), 'private bytes');
        $url = $media->buildPrivateUrl($owner, now()->addMinutes(5));
        $this->get($url)->assertOk();
        $media->increment('revision');
        $this->get($url)->assertNotFound();
    });
});

it('rechecks delivery policy for previously usable asset routes', function (string $route, bool $variation, bool $disableVisibility): void {
    Storage::fake('public');
    $owner = createAssetUser();
    $public = $route === 'public';
    $media = createAssetMedia(['is_public' => $public, 'uploaded_by' => $owner->id]);
    Storage::disk('public')->put($media->buildPath(), 'original bytes');
    $parameters = ['media' => $media->id];
    if ($variation) {
        $thumb = MediaImageVariation::create(['media_id' => $media->id, 'label' => 'thumb', 'width' => 10, 'height' => 10, 'size' => 10, 'format' => 'webp', 'quality' => 80]);
        Storage::disk('public')->put($thumb->getPath(), 'variation bytes');
        $parameters['v'] = 'thumb';
    }
    $url = match ($route) {
        'public' => route('nvl.media.assets.show', $parameters),
        'authenticated' => route('nvl.media.authenticated.show', $parameters),
        'signed' => URL::temporarySignedRoute('nvl.media.private.show', now()->addMinute(), [...$parameters, 'owner' => $owner->id, 'revision' => $media->revision]),
    };
    $this->actingAs($owner)->get($url)->assertOk();

    $alternative = $public ? 'direct' : ($route === 'signed' ? 'application' : 'signed_application');
    config(['nvl-media.delivery.disks.public.'.($public ? 'public' : 'private') => $disableVisibility
        ? false : ['mode' => $alternative, 'allowed' => [$alternative]]]);

    $this->get($url)->assertNotFound();
})->with(['public', 'authenticated', 'signed'])->with(['original' => false, 'variation' => true])->with(['visibility disabled' => true, 'route mode removed' => false]);
