<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Media\Actions\GrantMediaToTenantAction;
use Nvl\Media\Actions\ImportPlatformMediaAction;
use Nvl\Media\Actions\RevokeMediaTenantGrantAction;
use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Data\Mutations\ImportPlatformMediaData;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaTenantGrant;
use Nvl\Media\Services\MediaCatalogImporter;
use Nvl\Media\Tests\Fixtures\BarrierMediaCatalogImport;
use Nvl\Media\Tests\Fixtures\MediaTenancyScenario;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Return why the separate-process catalog race cannot run. */
function mediaCatalogConcurrencySkipReason(): ?string
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
        return 'The Media catalog race requires PostgreSQL or MySQL.';
    }
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('pcntl_exec')) {
        return 'The Media catalog race requires pcntl.';
    }

    return null;
}

/** @return array{source: Media, grant: MediaTenantGrant} */
function mediaCatalogConcurrencyFixture(MediaTenancyScenario $scenario, string $suffix): array
{
    $bytes = 'catalog-race-'.$suffix;
    $source = $scenario->platform(fn (): Media => Media::query()->forceCreate([
        ...app(TenantBoundary::class)->attributes('media.assets'),
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'disk' => 'tenant-disk',
        'filename' => $suffix.'.txt',
        'hash' => $suffix.'.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => strlen($bytes),
        'digest' => hash('sha256', $bytes),
        'storage_path' => 'media/platform/'.$suffix.'.txt',
        'type' => MediaType::DOCUMENT,
        'status' => MediaLifecycleStatus::Available,
    ]));
    $scenario->platform(fn () => Storage::disk($source->disk)->put($source->buildPath(), $bytes));
    $grant = $scenario->platform(fn (): MediaTenantGrant => app(GrantMediaToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));

    return ['source' => $source, 'grant' => $grant];
}

/**
 * Race actual import and revoke processes, releasing the selected winner first.
 *
 * @return array{import: array<string, mixed>, revoke: array<string, mixed>}
 */
function mediaCatalogConcurrencyRace(MediaTenantGrant $grant, Media $source, string $winner): array
{
    $paths = [];
    foreach (['import-ready', 'import-gate', 'revoke-ready', 'revoke-gate', 'import-result', 'revoke-result'] as $name) {
        $path = tempnam(sys_get_temp_dir(), 'media-catalog-'.$name.'-');
        if (! is_string($path)) {
            throw new RuntimeException('The Media catalog race could not allocate IPC files.');
        }
        $paths[$name] = $path;
    }
    $children = [];

    try {
        $importPid = pcntl_fork();
        if ($importPid === -1) {
            throw new RuntimeException('The Media import race could not fork.');
        }
        if ($importPid === 0) {
            try {
                DB::purge();
                Container::getInstance()->forgetScopedInstances();
                app()->scoped(MediaCatalogImport::class, fn (): BarrierMediaCatalogImport => new BarrierMediaCatalogImport(
                    app(MediaCatalogImporter::class),
                    $paths['import-ready'],
                    $paths['import-gate'],
                ));
                $copy = app(TenantRunner::class)->run(new TenantId(MediaTenancyScenario::A), fn (): Media => app(ImportPlatformMediaAction::class)->execute(
                    new ImportPlatformMediaData($grant->id, $grant->revision, $source->revision, (string) Str::uuid()),
                ));
                $result = ['ok' => true, 'copy_id' => $copy->id];
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
            }
            file_put_contents($paths['import-result'], json_encode($result, JSON_THROW_ON_ERROR));
            pcntl_exec('/usr/bin/true');
            exit(1);
        }
        $children['import'] = $importPid;

        $revokePid = pcntl_fork();
        if ($revokePid === -1) {
            throw new RuntimeException('The Media revoke race could not fork.');
        }
        if ($revokePid === 0) {
            try {
                DB::purge();
                Container::getInstance()->forgetScopedInstances();
                file_put_contents($paths['revoke-ready'], 'ready');
                $deadline = microtime(true) + 10;
                while (file_get_contents($paths['revoke-gate']) !== 'go') {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('The Media revoke race gate timed out.');
                    }
                    usleep(10_000);
                }
                $revoked = app(TenantRunner::class)->platform(
                    new PlatformOperation('fixture.catalog', 'test', 'fixture'),
                    fn (): MediaTenantGrant => app(RevokeMediaTenantGrantAction::class)->execute($grant->id, $grant->revision),
                );
                $result = ['ok' => true, 'revision' => $revoked->revision];
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
            }
            file_put_contents($paths['revoke-result'], json_encode($result, JSON_THROW_ON_ERROR));
            pcntl_exec('/usr/bin/true');
            exit(1);
        }
        $children['revoke'] = $revokePid;

        $deadline = microtime(true) + 10;
        while ((file_get_contents($paths['import-ready']) !== 'ready' || file_get_contents($paths['revoke-ready']) !== 'ready') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (file_get_contents($paths['import-ready']) !== 'ready' || file_get_contents($paths['revoke-ready']) !== 'ready') {
            throw new RuntimeException('The Media catalog workers did not reach the final-lock barrier.');
        }

        file_put_contents($paths[$winner.'-gate'], 'go');
        $status = 0;
        pcntl_waitpid($children[$winner], $status);
        expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        $loser = $winner === 'import' ? 'revoke' : 'import';
        file_put_contents($paths[$loser.'-gate'], 'go');
        pcntl_waitpid($children[$loser], $status);
        expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);

        /** @var array<string, mixed> $import */
        $import = json_decode((string) file_get_contents($paths['import-result']), true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $revoke */
        $revoke = json_decode((string) file_get_contents($paths['revoke-result']), true, flags: JSON_THROW_ON_ERROR);

        return ['import' => $import, 'revoke' => $revoke];
    } finally {
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status, WNOHANG);
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

it('linearizes catalog import against revocation with each process winning once', function (): void {
    $scenario = MediaTenancyScenario::install(catalogCopies: true);
    $importFirst = mediaCatalogConcurrencyFixture($scenario, 'import-first');
    $importResult = mediaCatalogConcurrencyRace($importFirst['grant'], $importFirst['source'], 'import');

    expect($importResult['import']['ok'])->toBeTrue()
        ->and($importResult['revoke']['ok'])->toBeTrue()
        ->and(DB::table(MediaTables::Media)->where('tenant_id', MediaTenancyScenario::A)->where('catalog_source_id', $importFirst['source']->id)->count())->toBe(1);

    $revokeFirst = mediaCatalogConcurrencyFixture($scenario, 'revoke-first');
    $filesBefore = Storage::disk('tenant-disk')->allFiles();
    sort($filesBefore);
    $revokeResult = mediaCatalogConcurrencyRace($revokeFirst['grant'], $revokeFirst['source'], 'revoke');
    $filesAfter = Storage::disk('tenant-disk')->allFiles();
    sort($filesAfter);

    expect($revokeResult['revoke']['ok'])->toBeTrue()
        ->and($revokeResult['import']['ok'])->toBeFalse()
        ->and($revokeResult['import']['message'])->toContain('changed during staging')
        ->and(DB::table(MediaTables::Media)->where('tenant_id', MediaTenancyScenario::A)->where('catalog_source_id', $revokeFirst['source']->id)->exists())->toBeFalse()
        ->and($filesAfter)->toBe($filesBefore);
})->skip(
    fn (): bool => mediaCatalogConcurrencySkipReason() !== null,
    'The Media catalog race requires PostgreSQL/MySQL and pcntl.',
);
