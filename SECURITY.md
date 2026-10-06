# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/media/security/advisories/new).

Security fixes are provided for the prepared `5.x` release line on PHP 8.3–8.4 and Laravel 13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include visibility, storage driver, delivery route, signature behavior, scanner result, range request, and impact without attaching malicious production files.

Private paths, disks, checksums, uploader identities, and quarantine details must not appear in public DTOs. Production deployments should require a real scanner, verify checksums server-side, authorize every private operation, and use short-lived signatures.
