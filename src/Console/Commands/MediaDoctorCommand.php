<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Media\Data\MediaDoctorCheckData;
use Nvl\Media\Services\MediaDoctor;

/**
 * Reports Media installation and deployment readiness without mutation.
 */
final class MediaDoctorCommand extends Command
{
    protected $signature = 'nvl:media:doctor
        {--production : Enforce production deployment requirements}
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect Media schema, disks, routes, scanner, and authorization bindings';

    /**
     * Execute read-only diagnostics.
     */
    public function handle(MediaDoctor $doctor): int
    {
        $checks = $doctor->inspect((bool) $this->option('production'));
        $format = $this->option('format');

        if ($format === 'json') {
            $this->line((string) json_encode([
                'package' => 'nvl/media',
                'healthy' => ! $this->hasFailures($checks),
                'checks' => array_map(
                    static fn (MediaDoctorCheckData $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Check', 'Severity', 'Result', 'Message'],
                array_map(
                    static fn (MediaDoctorCheckData $check): array => [
                        $check->key,
                        $check->severity,
                        $check->passed ? 'PASS' : 'FAIL',
                        $check->message,
                    ],
                    $checks,
                ),
            );
        }

        return $this->hasFailures($checks) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Determine whether error or strict-warning checks failed.
     *
     * @param  list<MediaDoctorCheckData>  $checks
     */
    private function hasFailures(array $checks): bool
    {
        $strict = (bool) $this->option('strict');

        foreach ($checks as $check) {
            if (! $check->passed && ($check->severity === 'error' || $strict)) {
                return true;
            }
        }

        return false;
    }
}
