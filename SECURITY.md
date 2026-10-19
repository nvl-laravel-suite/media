# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/media/security/advisories/new).

Security fixes are provided for the published `5.x` release line and the `6.x` line once published. Prepared 6.0.0 source does not establish release certification. Enabled Ownership requires Laravel 13; disabled/unowned hosts keep the declared framework range. Composer declares PHP `^8.4` and Laravel `^12.0|^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with MySQL 8.4 and PostgreSQL 17 persistence contracts; PHP 8.5, Laravel 12 and MariaDB require separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include visibility, storage driver, delivery route, signature behavior, scanner result, range request, and impact without attaching malicious production files.

Private paths, disks, checksums, uploader identities, and quarantine details must not appear in public DTOs. Production deployments should require a real scanner, verify checksums server-side, authorize every private operation, and use short-lived signatures.
