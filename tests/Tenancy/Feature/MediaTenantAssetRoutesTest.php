<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Tests\Fixtures\MediaTenancyDirectory;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantSiteAttributes;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;
use Nvl\Tenancy\Contracts\TenantSiteResolver;

beforeEach(function (): void {
    app()->instance(TenantSiteResolver::class, new class implements TenantSiteResolver
    {
        public function resolve(Request $request): TenantSiteContext
        {
            $tenant = match ($request->getHost()) {
                'a.media.test' => MediaTenancyScenario::A,
                'b.media.test' => MediaTenancyScenario::B,
                default => throw new RuntimeException('Unknown tenant host.'),
            };

            return new TenantSiteContext(new TenantId($tenant), 'media', 'https://'.$request->getHost());
        }
    });
});

it('binds private capabilities to tenant revision and canonical origin', function (string $attributeKey): void {
    $scenario = MediaTenancyScenario::install();
    $media = $scenario->upload($scenario::A, 'private route bytes', false);
    $signingRequest = Request::create('https://a.media.test');
    $signingRequest->attributes->set($attributeKey, new TenantSiteContext(new TenantId($scenario::A), 'media', 'https://a.media.test'));
    $resolveUrl = static fn (): string => (new MediaUrlResolver(
        app(MediaDiskGateway::class),
        app(MediaFileExistence::class),
        app(TenantContext::class),
        $signingRequest,
    ))->privateUrl($media);
    $url = $scenario->run($scenario::A, $resolveUrl);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://a.media.test/')
        ->and($query['tenant'] ?? null)->toBe($scenario::A)
        ->and($query['revision'] ?? null)->toBe((string) $media->revision);

    $signingRequest->attributes->set(TenantSiteAttributes::LegacyKey, new TenantSiteContext(new TenantId($scenario::A), 'media', 'https://a.media.test'));
    $signingRequest->attributes->set(TenantSiteContext::class, null);
    expect(fn (): string => $scenario->run($scenario::A, $resolveUrl))
        ->toThrow(TenantBoundaryViolation::class, 'A verified tenant site is required');

    $path = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
    $this->call('GET', 'https://a.media.test'.$path, server: ['HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, public');
    $this->call('HEAD', 'https://a.media.test'.$path, server: ['HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertOk();
    $this->call('GET', 'https://a.media.test'.$path, server: [
        'HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443,
        'HTTP_RANGE' => 'bytes=0-6',
    ])->assertStatus(206);
    $this->call('GET', 'https://b.media.test'.$path, server: ['HTTP_HOST' => 'b.media.test', 'SERVER_NAME' => 'b.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertNotFound();

    $scenario->run($scenario::A, static function () use ($media): void {
        Media::query()->whereKey($media->id)->increment('revision');
    });
    $this->call('GET', 'https://a.media.test'.$path, server: ['HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertNotFound();
})->with([TenantSiteContext::class, TenantSiteAttributes::LegacyKey]);

it('returns enumeration-neutral not found when the signed tenant becomes suspended', function (): void {
    $scenario = MediaTenancyScenario::install();
    $media = $scenario->upload($scenario::A, 'suspended route bytes', false);
    $signingRequest = Request::create('https://a.media.test');
    $signingRequest->attributes->set(TenantSiteContext::class, new TenantSiteContext(new TenantId($scenario::A), 'media', 'https://a.media.test'));
    $url = $scenario->run($scenario::A, static fn (): string => (new MediaUrlResolver(
        app(MediaDiskGateway::class),
        app(MediaFileExistence::class),
        app(TenantContext::class),
        $signingRequest,
    ))->privateUrl($media));
    $directory = app(TenantDirectory::class);
    expect($directory)->toBeInstanceOf(MediaTenancyDirectory::class);
    $directory->status = TenantStatus::Suspended;

    $path = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
    $this->call('GET', 'https://a.media.test'.$path, server: ['HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertNotFound();
});

it('rejects a signed tenant selector that conflicts with the verified site', function (): void {
    $scenario = MediaTenancyScenario::install();
    $media = $scenario->upload($scenario::A, 'private selector bytes', false);
    $signingRequest = Request::create('https://a.media.test');
    $signingRequest->attributes->set(TenantSiteContext::class, new TenantSiteContext(new TenantId($scenario::A), 'media', 'https://a.media.test'));
    $url = $scenario->run($scenario::A, static fn (): string => (new MediaUrlResolver(
        app(MediaDiskGateway::class),
        app(MediaFileExistence::class),
        app(TenantContext::class),
        $signingRequest,
    ))->privateUrl($media));
    $conflicting = str_replace('tenant='.$scenario::A, 'tenant='.$scenario::B, $url);

    $path = (string) parse_url($conflicting, PHP_URL_PATH).'?'.(string) parse_url($conflicting, PHP_URL_QUERY);
    $this->call('GET', 'https://a.media.test'.$path, server: ['HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertForbidden();
});
