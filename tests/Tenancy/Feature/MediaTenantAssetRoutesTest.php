<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaDiskGateway;
use Nvl\Media\Services\MediaFileExistence;
use Nvl\Media\Services\MediaUrlResolver;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

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

it('binds private capabilities to tenant revision and canonical origin', function (): void {
    $scenario = MediaTenancyScenario::install();
    $media = $scenario->upload($scenario::A, 'private route bytes', false);
    $signingRequest = Request::create('https://a.media.test');
    $signingRequest->attributes->set(TenantSiteContext::class, new TenantSiteContext(new TenantId($scenario::A), 'media', 'https://a.media.test'));
    $url = $scenario->run($scenario::A, static fn (): string => (new MediaUrlResolver(
        app(MediaDiskGateway::class),
        app(MediaFileExistence::class),
        app(TenantContext::class),
        $signingRequest,
    ))->privateUrl($media));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://a.media.test/')
        ->and($query['tenant'] ?? null)->toBe($scenario::A)
        ->and($query['revision'] ?? null)->toBe((string) $media->revision);

    $path = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
    $this->call('GET', 'https://a.media.test'.$path, server: ['HTTP_HOST' => 'a.media.test', 'SERVER_NAME' => 'a.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, public');
    $this->call('GET', 'https://b.media.test'.$path, server: ['HTTP_HOST' => 'b.media.test', 'SERVER_NAME' => 'b.media.test', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
        ->assertNotFound();

    $scenario->run($scenario::A, static function () use ($media): void {
        Media::query()->whereKey($media->id)->increment('revision');
    });
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
