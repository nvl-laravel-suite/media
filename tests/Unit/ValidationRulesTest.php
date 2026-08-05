<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Nvl\Media\Http\Rules\AllowedMimeTypes;
use Nvl\Media\Http\Rules\AspectRatio;
use Nvl\Media\Http\Rules\ImageDimensions;
use Nvl\Media\Http\Rules\MaxFileSize;

/* =================================================================
 * AllowedMimeTypes
 * ================================================================= */

describe('AllowedMimeTypes', function () {

    it('passes for allowed MIME type', function () {
        $rule = new AllowedMimeTypes(['image/jpeg', 'image/png']);
        $file = UploadedFile::fake()->image('photo.jpg');
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('fails for disallowed MIME type', function () {
        $rule = new AllowedMimeTypes(['image/png']);
        $file = UploadedFile::fake()->image('photo.jpg');
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('unsupported MIME type');
    });

    it('fails for non-file input', function () {
        $rule = new AllowedMimeTypes(['image/jpeg']);
        $message = '';

        $rule->validate('file', 'not-a-file', function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('must be a file');
    });

    it('reads defaults from config when no constructor arg', function () {
        config(['media.file_types' => ['image/jpeg' => 'image/jpeg', 'image/png' => 'image/png']]);

        $rule = new AllowedMimeTypes;
        $file = UploadedFile::fake()->image('photo.jpg');
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });
});

/* =================================================================
 * MaxFileSize
 * ================================================================= */

describe('MaxFileSize', function () {

    it('passes when file is under limit', function () {
        $rule = new MaxFileSize(10 * 1024 * 1024); // 10 MB
        $file = UploadedFile::fake()->image('photo.jpg')->size(100); // 100 KB
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('fails when file exceeds limit', function () {
        $rule = new MaxFileSize(1024); // 1 KB
        $file = UploadedFile::fake()->image('photo.jpg')->size(100); // 100 KB
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('must not exceed');
    });

    it('reads config default when no constructor arg', function () {
        config(['media.max_file_size' => 500]);
        $rule = new MaxFileSize;
        $file = UploadedFile::fake()->image('photo.jpg')->size(1); // 1 KB = 1024 bytes > 500
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('must not exceed');
    });

    it('fails for non-file input', function () {
        $rule = new MaxFileSize(1024);
        $message = '';

        $rule->validate('file', 'not-a-file', function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('must be a file');
    });
});

/* =================================================================
 * ImageDimensions
 * ================================================================= */

describe('ImageDimensions', function () {

    it('passes when image is within constraints', function () {
        $rule = new ImageDimensions(4096, 4096);
        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('fails when width exceeds maximum', function () {
        $rule = new ImageDimensions(100, 4096);
        $file = UploadedFile::fake()->image('photo.jpg', 200, 50);
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('width');
    });

    it('fails when height exceeds maximum', function () {
        $rule = new ImageDimensions(4096, 100);
        $file = UploadedFile::fake()->image('photo.jpg', 50, 200);
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('height');
    });

    it('skips non-image files', function () {
        $rule = new ImageDimensions(100, 100);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('skips non-file input silently', function () {
        $rule = new ImageDimensions(100, 100);
        $failed = false;

        $rule->validate('file', 'not-a-file', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });
});

/* =================================================================
 * AspectRatio
 * ================================================================= */

describe('AspectRatio', function () {

    it('passes when ratio is within tolerance', function () {
        // 200x200 = ratio 1.0, target 1.0
        $rule = new AspectRatio(1.0, 0.05);
        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('fails when ratio is outside tolerance', function () {
        // 400x100 = ratio 4.0, target 1.0
        $rule = new AspectRatio(1.0, 0.05);
        $file = UploadedFile::fake()->image('photo.jpg', 400, 100);
        $message = '';

        $rule->validate('file', $file, function ($msg) use (&$message) {
            $message = $msg;
        });

        expect($message)->toContain('aspect ratio');
    });

    it('skips non-image files', function () {
        $rule = new AspectRatio(1.0);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('skips non-file input silently', function () {
        $rule = new AspectRatio(1.0);
        $failed = false;

        $rule->validate('file', 'not-a-file', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });

    it('accepts custom tolerance', function () {
        // 210x200 = ratio 1.05, target 1.0, tolerance 0.1 -> should pass
        $rule = new AspectRatio(1.0, 0.1);
        $file = UploadedFile::fake()->image('photo.jpg', 210, 200);
        $failed = false;

        $rule->validate('file', $file, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeFalse();
    });
});
