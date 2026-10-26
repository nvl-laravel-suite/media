<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Nvl\Media\Contracts\MediaCatalogImport;
use Nvl\Media\Models\Media;
use RuntimeException;

/** Pauses a test import immediately before the real port acquires its final grant lock. */
final readonly class BarrierMediaCatalogImport implements MediaCatalogImport
{
    public function __construct(private MediaCatalogImport $inner, private string $readyPath, private string $gatePath) {}

    public function inspect(string $grantId): array
    {
        return $this->inner->inspect($grantId);
    }

    public function stage(array $source): array
    {
        return $this->inner->stage($source);
    }

    public function persist(array $source, array $file, string $idempotencyKey): Media
    {
        if (file_put_contents($this->readyPath, 'ready') === false) {
            throw new RuntimeException('The Media import race could not signal readiness.');
        }
        $deadline = microtime(true) + 10;
        while (file_get_contents($this->gatePath) !== 'go') {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The Media import race gate timed out.');
            }
            usleep(10000);
        }

        return $this->inner->persist($source, $file, $idempotencyKey);
    }

    public function discard(array $file): void
    {
        $this->inner->discard($file);
    }
}
