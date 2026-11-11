<?php

declare(strict_types=1);

namespace Nvl\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Contracts\MultipartUploadGateway;
use Nvl\Media\Enums\MediaMultipartStatus;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\MediaMultipartUpload;
use Nvl\Media\Services\MediaFileOperator;
use Nvl\Media\Services\MediaMultipartLock;
use Nvl\Media\Services\MediaMultipartSessionMapper;
use Nvl\Support\Facades\PackageLog;
use Throwable;

/**
 * Aborts expired provider uploads, deletes an object their completion may have assembled and records their terminal session state.
 */
final class PruneExpiredMultipartUploadsCommand extends Command
{
    protected $signature = 'nvl:media:multipart:prune
        {--limit=500 : Maximum expired sessions to process}';

    protected $description = 'Abort and expire unfinished multipart media sessions';

    public function __construct(
        private readonly MultipartUploadGateway $gateway,
        private readonly MediaMultipartLock $lock,
        private readonly MediaMultipartSessionMapper $sessionMapper,
        private readonly MediaFileOperator $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $expiredAt = now()->utc();
        $sessionIds = MediaMultipartUpload::query()
            ->where(function (Builder $query) use ($expiredAt): void {
                $query
                    ->where(function (Builder $active) use ($expiredAt): void {
                        $active
                            ->whereIn('status', [
                                MediaMultipartStatus::Initiated->value,
                                MediaMultipartStatus::Completing->value,
                            ])
                            ->where('expires_at', '<=', $expiredAt);
                    })
                    ->orWhere('status', MediaMultipartStatus::Expired->value)
                    ->orWhere(function (Builder $failed): void {
                        $failed
                            ->where('status', MediaMultipartStatus::Failed->value)
                            ->where('failure_code', 'provider_initiation_cleanup_pending');
                    });
            })
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values();
        $expired = 0;
        $failed = 0;

        foreach ($sessionIds as $sessionId) {
            try {
                $didExpire = $this->lock->execute($sessionId, function () use ($sessionId): bool {
                    $session = MediaMultipartUpload::query()->find($sessionId);

                    if (! $session instanceof MediaMultipartUpload
                        || ! $this->requiresProviderCleanup($session)) {
                        return false;
                    }

                    $this->gateway->abort($this->sessionMapper->toData($session));

                    // Only a session that reached completion can have an assembled object at its server-owned key.
                    if ($session->status !== MediaMultipartStatus::Initiated && ! $this->files->delete($session->disk, $session->object_key)) {
                        throw new MediaUploadException('The assembled multipart object could not be deleted.');
                    }

                    $failureCode = $session->status === MediaMultipartStatus::Failed
                        ? 'provider_initiation_failed'
                        : 'session_expired';
                    $session->forceFill([
                        'status' => MediaMultipartStatus::Aborted,
                        'provider_state' => null,
                        'failure_code' => $failureCode,
                    ])->save();

                    return true;
                });

                if ($didExpire) {
                    $expired++;
                }
            } catch (Throwable $exception) {
                $failed++;
                PackageLog::log('media', 'error', 'nvl.media.multipart_prune.failed', [
                    'session_id' => $sessionId,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ], 'normal');
            }
        }

        $this->components->info(
            "Cleaned multipart sessions: {$expired}; cleanup failures: {$failed}.",
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function requiresProviderCleanup(MediaMultipartUpload $session): bool
    {
        if ($session->status === MediaMultipartStatus::Expired) {
            return true;
        }

        if ($session->status === MediaMultipartStatus::Failed) {
            return $session->failure_code === 'provider_initiation_cleanup_pending';
        }

        return in_array(
            $session->status,
            [MediaMultipartStatus::Initiated, MediaMultipartStatus::Completing],
            true,
        ) && ! $session->expires_at->isFuture();
    }
}
