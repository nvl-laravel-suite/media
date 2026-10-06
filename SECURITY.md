# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/media/security/advisories/new).

The prepared `5.x` release line targets PHP 8.4–8.5 and Laravel 12–13. This compatibility matrix remains candidate/unverified until same-source execution evidence is reviewed; upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include visibility, storage driver, delivery route, signature behavior, scanner result, range request, and impact without attaching malicious production files.

Private paths, disks, checksums, uploader identities, and quarantine details must not appear in public DTOs. Production deployments should require a real scanner, verify checksums server-side, authorize every private operation, and use short-lived signatures.
