<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Nvl\Media\Adoption\LegacyMediaGraph;
use Nvl\Support\Enums\SystemActor;

/**
 * Stages legacy px_media records and their companion graph on the existing disks.
 *
 * Compatibility wrapper: hosts run new adoptions through `nvl:adopt media` and MediaAdoptionAdapter.
 *
 * @api
 *
 * @phpstan-type Source array{connection?: string, root_folder?: string, conversions_folder?: string, uploader_type?: string, owner_types?: array<string,string>}
 * @phpstan-type Review array{schema_fingerprint:string, fingerprint:string, source:array<string,int>, staged:array<string,int>, conflicts:list<string>}
 */
final readonly class MediaSchemaAdopter
{
    private LegacyMediaGraph $graph;

    public function __construct(DatabaseManager $database, MediaAccessService $access, MediaFileExistence $existence)
    {
        $this->graph = new LegacyMediaGraph($database, $access, $existence);
    }

    /**
     * Review a quiescent legacy graph. The target must be empty or contain only matching staged rows.
     *
     * @param  Source  $source
     * @return Review
     */
    public function plan(Authenticatable|SystemActor $actor, array $source = []): array
    {
        $this->admit($actor, $source);

        return $this->inspect($source);
    }

    /**
     * Stage a reviewed graph in committed batches. Matching batches survive an interruption.
     *
     * @param  Source  $source
     * @return Review
     */
    public function apply(Authenticatable|SystemActor $actor, string $fingerprint, array $source = []): array
    {
        $this->admit($actor, $source, committed: true);
        $review = $this->inspect($source);
        $this->assertReviewed($fingerprint, $review);
        if ($review['source'] !== $review['staged']) {
            $this->assertReviewed($fingerprint, $this->inspect($source, write: true));
            $review = $this->inspect($source);
        }

        return $this->assertReconciled($fingerprint, $review);
    }

    /**
     * Reconcile committed rows and backing files before the host cuts over.
     *
     * @param  Source  $source
     * @return Review
     */
    public function verify(Authenticatable|SystemActor $actor, string $fingerprint, array $source = []): array
    {
        $this->admit($actor, $source, committed: true);

        return $this->assertReconciled($fingerprint, $this->inspect($source));
    }

    /** @param Source $source */
    private function admit(Authenticatable|SystemActor $actor, array $source, bool $committed = false): void
    {
        $this->graph->admit($actor, $source, $committed);
    }

    /**
     * @param  Source  $source
     * @return Review
     */
    private function inspect(array $source, bool $write = false): array
    {
        $scan = $this->graph->scan($source, $write);

        return [
            'schema_fingerprint' => $scan['schema_fingerprint'], 'fingerprint' => $scan['fingerprint'],
            'source' => $scan['source'], 'staged' => $scan['staged'], 'conflicts' => $scan['conflicts'],
        ];
    }

    /** @param Review $review */
    private function assertReviewed(string $fingerprint, array $review): void
    {
        if (! hash_equals($fingerprint, $review['fingerprint'])) {
            throw new LogicException('Media adoption fingerprint changed. Review a new plan.');
        }
        if ($review['conflicts'] !== []) {
            throw new LogicException('Media adoption has conflicts. '.$review['conflicts'][0]);
        }
    }

    /** @param Review $review
     * @return Review
     */
    private function assertReconciled(string $fingerprint, array $review): array
    {
        $this->assertReviewed($fingerprint, $review);
        if ($review['source'] !== $review['staged']) {
            throw new LogicException('Media adoption reconciliation is incomplete.');
        }

        return $review;
    }
}
