<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/** Process-local maintenance state for the standalone Media consumer. */
final class MediaTenancyConsumerMaintenanceMode implements MaintenanceMode
{
    private bool $enabled = false;

    /** @var array<string, mixed> */
    private array $payload = [];

    /** @param array<string, mixed> $payload */
    public function activate(array $payload): void
    {
        $this->enabled = true;
        $this->payload = $payload;
    }

    /** End fixture maintenance. */
    public function deactivate(): void
    {
        $this->enabled = false;
        $this->payload = [];
    }

    /** Report whether fixture maintenance is active. */
    public function active(): bool
    {
        return $this->enabled;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->payload;
    }
}
