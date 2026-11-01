<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use DateTimeInterface;
use InvalidArgumentException;
use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Enums\MediaVisibility;
use RuntimeException;

/**
 * Validates disk delivery capabilities and resolves bounded caller choices.
 *
 * @api
 */
final class MediaDeliveryPolicy
{
    /**
     * Resolve a disk's default or an explicitly admitted override for the visibility.
     */
    public function mode(string $disk, MediaVisibility $visibility, ?MediaDeliveryMode $override = null): MediaDeliveryMode
    {
        $settings = $this->settings($disk, $visibility);
        $mode = $override ?? $settings['mode'];

        if (! in_array($mode, $settings['allowed'], true)) {
            throw new RuntimeException("Media delivery [{$mode->value}] is not allowed for [{$visibility->value}] assets on disk [{$disk}].");
        }

        return $mode;
    }

    /** Assert that a disk admits storage and delivery of the logical visibility. */
    public function assertVisibility(string $disk, MediaVisibility $visibility): void
    {
        $this->settings($disk, $visibility);
    }

    /** Validate declared disk and visibility policies without reading or writing objects. */
    public function validate(): void
    {
        $configuration = $this->configuration();
        $defaults = $configuration['defaults'] ?? [];
        if (! is_array($defaults) || array_diff(array_keys($defaults), ['public', 'private']) !== []) {
            throw new InvalidArgumentException('Media delivery defaults must contain only public and private policies.');
        }
        foreach (MediaVisibility::cases() as $visibility) {
            if (($defaults[$visibility->value] ?? null) !== false) {
                $this->settings('', $visibility);
            }
        }

        $disks = $configuration['disks'] ?? [];
        if (! is_array($disks)) {
            throw new InvalidArgumentException('Media delivery disks must be a disk-name-to-policy map.');
        }
        foreach ($disks as $disk => $definition) {
            if (! is_string($disk) || trim($disk) === '' || config("filesystems.disks.{$disk}") === null) {
                throw new InvalidArgumentException('Every media delivery policy must name a defined filesystem disk.');
            }
            $policy = $this->diskSettings($configuration, $disk);
            foreach (MediaVisibility::cases() as $visibility) {
                if (array_key_exists($visibility->value, $policy)
                    ? $policy[$visibility->value] !== false
                    : ($defaults[$visibility->value] ?? null) !== false) {
                    $this->settings($disk, $visibility);
                }
            }
        }
    }

    /** Reject expired capabilities and lifetimes exceeding the disk's bound. */
    public function assertExpiration(string $disk, DateTimeInterface $expiration): void
    {
        $maximum = $this->maximumTtl($disk);
        $lifetime = $expiration->getTimestamp() - now()->getTimestamp();

        if ($lifetime <= 0 || $lifetime > $maximum) {
            throw new RuntimeException("Media link expiration must be in the next [{$maximum}] seconds for disk [{$disk}].");
        }
    }

    /** Return the validated maximum capability lifetime for a disk, in seconds. */
    public function maximumTtl(string $disk): int
    {
        $settings = $this->configuration();
        $diskSettings = $this->diskSettings($settings, $disk);

        return $this->maximumLifetime($diskSettings['maximum_ttl'] ?? $settings['maximum_ttl'] ?? 300);
    }

    /**
     * Resolve and validate one visibility's effective settings without mutating configuration.
     *
     * @return array{mode: MediaDeliveryMode, allowed: list<MediaDeliveryMode>}
     */
    private function settings(string $disk, MediaVisibility $visibility): array
    {
        $configuration = $this->configuration();
        $diskSettings = $this->diskSettings($configuration, $disk);
        $defaults = array_key_exists('defaults', $configuration) ? $configuration['defaults'] : [];

        if (! is_array($defaults) || array_diff(array_keys($defaults), ['public', 'private']) !== []) {
            throw new InvalidArgumentException('Media delivery defaults must contain only public and private policies.');
        }

        $base = array_key_exists($visibility->value, $defaults) ? $defaults[$visibility->value] : [];
        $hasOverride = array_key_exists($visibility->value, $diskSettings);
        $override = $hasOverride ? $diskSettings[$visibility->value] : [];

        if (($base === false && ! $hasOverride) || $override === false) {
            throw new RuntimeException("Disk [{$disk}] does not admit [{$visibility->value}] media.");
        }
        if ($base === false) {
            $base = [];
        }

        if (! is_array($base) || ! is_array($override)
            || array_diff(array_keys($base), ['mode', 'allowed']) !== []
            || array_diff(array_keys($override), ['mode', 'allowed']) !== []) {
            throw new InvalidArgumentException('A media visibility policy must contain only mode and allowed settings, or be false.');
        }

        $effective = array_replace([
            'mode' => MediaDeliveryMode::Application,
            'allowed' => $visibility === MediaVisibility::Public
                ? [MediaDeliveryMode::Application, MediaDeliveryMode::Direct]
                : [MediaDeliveryMode::Application, MediaDeliveryMode::SignedApplication, MediaDeliveryMode::TemporaryStorage],
        ], $base, $override);
        $mode = $this->parseMode($effective['mode']);
        $allowed = $effective['allowed'];

        if (! is_array($allowed) || ! array_is_list($allowed) || $allowed === []) {
            throw new InvalidArgumentException('Media delivery allowed must be a non-empty list of modes.');
        }

        $modes = array_map($this->parseMode(...), $allowed);
        $supported = $visibility === MediaVisibility::Public
            ? [MediaDeliveryMode::Application, MediaDeliveryMode::Direct]
            : [MediaDeliveryMode::Application, MediaDeliveryMode::SignedApplication, MediaDeliveryMode::TemporaryStorage];

        foreach ($modes as $allowedMode) {
            if (! in_array($allowedMode, $supported, true)) {
                throw new InvalidArgumentException("Delivery [{$allowedMode->value}] is incompatible with [{$visibility->value}] media.");
            }
        }

        if (! in_array($mode, $modes, true)) {
            throw new InvalidArgumentException('The default media delivery mode must be in the allowed list.');
        }

        return ['mode' => $mode, 'allowed' => array_values(array_unique($modes, SORT_REGULAR))];
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $configuration = $this->configurationMap(config('nvl-media.delivery', []), ['defaults', 'disks', 'maximum_ttl']);

        if (array_key_exists('maximum_ttl', $configuration)) {
            $this->maximumLifetime($configuration['maximum_ttl']);
        }
        foreach (['defaults', 'disks'] as $key) {
            if (array_key_exists($key, $configuration) && ! is_array($configuration[$key])) {
                throw new InvalidArgumentException("Media delivery [{$key}] must be a configuration map.");
            }
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function diskSettings(array $configuration, string $disk): array
    {
        $disks = $configuration['disks'] ?? [];

        if (! is_array($disks)) {
            throw new InvalidArgumentException('Media delivery disks must be a disk-name-to-policy map.');
        }

        $settings = array_key_exists($disk, $disks) ? $disks[$disk] : [];

        $settings = $this->configurationMap($settings, ['public', 'private', 'maximum_ttl']);
        if (array_key_exists('maximum_ttl', $settings)) {
            $this->maximumLifetime($settings['maximum_ttl']);
        }

        return $settings;
    }

    /** Validate lifetime bounds for every URL read, including nonsigning operations. */
    private function maximumLifetime(mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > 604800) {
            throw new InvalidArgumentException('Media delivery maximum_ttl must be an integer between 1 and 604800 seconds.');
        }

        return $value;
    }

    /**
     * Validate the named keys of a delivery configuration map without coercing values.
     *
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function configurationMap(mixed $value, array $allowed): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Media delivery settings must be configuration maps.');
        }
        $settings = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown media delivery configuration setting.');
            }
            $settings[$key] = $entry;
        }

        return $settings;
    }

    /** Parse enums and their serialized configuration values without coercion. */
    private function parseMode(mixed $value): MediaDeliveryMode
    {
        if ($value instanceof MediaDeliveryMode) {
            return $value;
        }

        if (is_string($value) && ($mode = MediaDeliveryMode::tryFrom($value)) instanceof MediaDeliveryMode) {
            return $mode;
        }

        throw new InvalidArgumentException('Invalid media delivery mode.');
    }
}
