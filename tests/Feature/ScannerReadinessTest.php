<?php

declare(strict_types=1);
use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaScannerPolicy;

it('keeps explicit development bypass but diagnoses a required noop as a strict error', function (): void {
    config()->set('nvl-media.scanner.required', true);
    config()->set('nvl-media.scanner.allow_noop', true);
    app(MediaScannerPolicy::class)->assertReady();
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'scanner.required');
    expect($check['passed'])->toBeFalse()->and($check['severity'])->toBe('error');
    $this->artisan('nvl:media:doctor', ['--strict' => true, '--format' => 'json'])->assertFailed();
});
it('preserves a host scanner binding and diagnoses it without scanning files', function (): void {
    $scanner = new class implements MediaContentScanner
    {
        public function scan(UploadedFile $file): void
        {
            throw new RuntimeException('Doctor must not scan');
        }
    };
    app()->instance(MediaContentScanner::class, $scanner);
    (new MediaServiceProvider(app()))->register();
    config()->set('nvl-media.scanner.required', true);
    expect(app(MediaContentScanner::class))->toBe($scanner);
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'scanner.required');
    expect($check['passed'])->toBeTrue();
});

it('requires a real scanner in production unless the production bypass is explicitly enabled', function (): void {
    app()->instance('env', 'production');
    config(['nvl-media.scanner.required' => false, 'nvl-media.scanner.allow_noop' => true]);
    expect(fn () => app(MediaScannerPolicy::class)->assertReady())->toThrow(MediaUploadException::class, 'no production MediaContentScanner');
    $diagnostics = static fn () => collect(app(MediaDoctor::class)->inspect(true))->keyBy('key');
    expect($diagnostics()['scanner.production'])->toMatchArray(['passed' => false, 'severity' => 'error']);

    config(['nvl-media.scanner.allow_unscanned_production' => true]);
    app(MediaScannerPolicy::class)->assertReady();
    expect($diagnostics()['scanner.production_bypass'])->toMatchArray(['passed' => false, 'severity' => 'warning']);

    config(['nvl-media.scanner.untrusted_uploads' => false]);
    expect(fn () => app(MediaScannerPolicy::class)->assertReady())->toThrow(MediaUploadException::class, 'uploads are disabled');
    expect($diagnostics()['scanner.production'])->toMatchArray(['passed' => true]);
});
