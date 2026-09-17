<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Services\MediaReplacementStager;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;

it('keeps a completed multipart folder logical when staging its replacement', function (): void {
    $scenario = MediaTenancyScenario::install();
    $media = $scenario->upload($scenario::A, 'multipart original');

    $scenario->run($scenario::A, function () use ($media, $scenario): void {
        $physical = 'media/tenants/'.$scenario::A.'/multipart/session/original.txt';
        $logical = app(MediaPathResolver::class)->logicalFolderFromStoragePath($physical);
        $media->forceFill(['folder' => $logical, 'storage_path' => $physical])->save();

        $path = tempnam(sys_get_temp_dir(), 'nvl-media-replacement-');
        expect($path)->toBeString();
        file_put_contents($path, 'multipart replacement');
        try {
            $staged = app(MediaReplacementStager::class)->stage(
                $media->refresh(),
                new UploadedFile($path, 'replacement.txt', 'text/plain', null, true),
            );
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }

        expect($staged->path)->toStartWith('media/tenants/'.$scenario::A.'/multipart/session/')
            ->not->toContain('/tenants/'.$scenario::A.'/tenants/');
    });
});
