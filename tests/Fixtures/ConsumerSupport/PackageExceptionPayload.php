<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures\ConsumerSupport;

use Illuminate\Contracts\Translation\Translator;
use Nvl\Support\Contracts\RespondableException;
use stdClass;

/** Builds safe localized public payloads without consulting exception diagnostics.
 * @internal
 */
final readonly class PackageExceptionPayload
{
    public function __construct(private Translator $translator) {}

    /** @return array{message: string, code: string, context: stdClass|array<array-key, mixed>} */
    public function for(RespondableException $exception): array
    {
        $key = $exception->translationKey();
        $code = $key === null || str_ends_with($key, '::responsecode.operation_failed')
            ? 'operation_failed'
            : ($exception->responseCode() ?? 'operation_failed');
        $parameters = $this->translationParameters($exception->translationParameters());
        $message = $key === null ? null : $this->translator->get($key, $parameters);
        if (! is_string($message) || $message === '' || $message === $key) {
            $fallbackKey = 'nvl-core::responsecode.operation_failed';
            $fallback = $this->translator->get($fallbackKey);
            $message = is_string($fallback) && $fallback !== '' && $fallback !== $fallbackKey
                ? $fallback
                : ($this->translator->getLocale() === 'bg' ? 'Операцията не може да бъде завършена.' : 'The operation could not be completed.');
        }

        $publicContext = $exception->publicContext();
        $allowlist = match ($exception->package().':'.$code) {
            'auth:feature_unavailable' => ['feature', 'operation', 'dependencies'],
            'filterable:'.$code => ['path'],
            'content:stale_content' => ['resource_id', 'expected_revision', 'actual_revision'],
            'content:definition_migration_required', 'content:definition_migration_failed' => ['block_id', 'definition', 'stored_version', 'current_version', 'from_version', 'to_version'],
            'media:media_in_use' => ['media_id', 'association_count'],
            'seo:invalid_seo_mutation' => ['errors'],
            'seo:seo_path_conflict' => ['scope', 'locale', 'path', 'profileId'],
            'seo:stale_seo_profile' => ['profileId'],
            'seo:stale_seo_redirect' => ['redirectId'],
            default => array_keys($publicContext),
        };
        $context = $this->sanitize(array_intersect_key($publicContext, array_fill_keys($allowlist, true)));
        if (in_array($code, ['binding_required', 'event_commit_unavailable', 'invalid_configuration', 'definition_cache_invalid'], true)) {
            $context = [];
        }

        return ['message' => $message, 'code' => $code, 'context' => $context === [] ? new stdClass : $context];
    }

    /**
     * Validate actual collaborator metadata before passing it to the translator.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, scalar|null>
     */
    private function translationParameters(array $parameters): array
    {
        return array_filter($parameters, static fn (mixed $value): bool => $value === null || is_scalar($value));
    }

    /** Return only deliberately supported, injection-safe response headers.
     * @return array<string, string>
     */
    public function headers(RespondableException $exception): array
    {
        $headers = [];
        foreach ($exception->responseHeaders() as $name => $value) {
            if (strcasecmp($name, 'Retry-After') === 0 && preg_match('/\A[0-9]{1,10}\z/D', $value) === 1) {
                $headers['Retry-After'] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitize(array $values, int $depth = 0): array
    {
        if ($depth > 32) {
            return [];
        }
        $safe = [];
        foreach ($values as $key => $value) {
            if ($value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) {
                $safe[$key] = $value;
            } elseif (is_array($value)) {
                $safe[$key] = $this->sanitize($value, $depth + 1);
            }
        }

        return array_is_list($values) ? array_values($safe) : $safe;
    }
}
