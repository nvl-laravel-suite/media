<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Nvl\Media\Contracts\MediaHostResolver;

/**
 * Resolves A and AAAA records through the host operating system.
 */
final class SystemMediaHostResolver implements MediaHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $normalizedHost = trim($host, '[]');

        if (filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            return [$normalizedHost];
        }

        $ips = [];
        $records = @dns_get_record($normalizedHost, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4Records = gethostbynamel($normalizedHost);

            if (is_array($ipv4Records)) {
                $ips = $ipv4Records;
            }
        }

        return array_values(array_unique(array_filter(
            $ips,
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
        )));
    }
}
