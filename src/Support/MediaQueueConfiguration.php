<?php

declare(strict_types=1);

namespace Nvl\Media\Support;

/**
 * Provides typed access to Media queue and per-job runtime configuration.
 */
final class MediaQueueConfiguration
{
    /**
     * Determine whether image variation work should use a durable queue.
     */
    public static function enabled(): bool
    {
        return config('media.queue.enabled', true) === true
            && self::connection() !== 'sync';
    }

    /**
     * Resolve the queue connection used by Media jobs.
     */
    public static function connection(): string
    {
        return MediaConfiguration::string('media.queue.connection', 'sync');
    }

    /**
     * Resolve the dedicated Media queue name.
     */
    public static function name(): string
    {
        return MediaConfiguration::string('media.queue.name', 'media');
    }

    /**
     * Resolve a positive integer setting for a named Media job.
     */
    public static function jobInteger(string $job, string $key, int $default): int
    {
        return MediaConfiguration::integer("media.queue.jobs.{$job}.{$key}", $default, 1);
    }

    /**
     * Resolve a positive retry-backoff sequence for a named Media job.
     *
     * @param  list<int>  $default
     * @return list<int>
     */
    public static function backoff(string $job, array $default): array
    {
        $configured = config("media.queue.jobs.{$job}.backoff", $default);

        if (! is_array($configured)) {
            return $default;
        }

        $backoff = array_values(array_filter(
            $configured,
            static fn (mixed $seconds): bool => is_int($seconds) && $seconds > 0,
        ));

        return $backoff !== [] ? $backoff : $default;
    }

    private function __construct() {}
}
