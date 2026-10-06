<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaDoctor;
use Nvl\Media\Services\MediaScannerPolicy;

it('keeps explicit development bypass but diagnoses a required noop as a strict error', function (): void {
    config()->set('nvl-media.scanner.required', true);
    config()->set('nvl-media.scanner.allow_noop', true);
    app(MediaScannerPolicy::class)->assertReady();
    $check = collect(app(MediaDoctor::class)->inspect())->firstWhere('key', 'scanner.required');
    expect($check->passed)->toBeFalse()->and($check->severity)->toBe('error');
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
    expect($check->passed)->toBeTrue();
});
