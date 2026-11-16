<?php

declare(strict_types=1);

namespace Nvl\Media\Adoption;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Nvl\Support\Adoption\AdoptionAdapter;
use Nvl\Support\Adoption\AdoptionBatch;
use Nvl\Support\Adoption\AdoptionException;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionStep;
use Nvl\Support\Adoption\AdoptionVerification;
use Nvl\Support\Enums\SystemActor;
use stdClass;

/**
 * Stages the legacy px_media graph through `nvl:adopt media`: assets, then associations, variations and translations.
 *
 * Files stay where they are on their disks; a missing backing file is a collision and fails verification.
 *
 * @internal Hosts reach it only through nvl:adopt.
 *
 * @phpstan-import-type Source from LegacyMediaGraph
 */
final class MediaAdoptionAdapter extends AdoptionAdapter
{
    /** Version of the legacy row mapping; bump it whenever mapped rows change. */
    private const string Version = '1';

    /** @var Source */
    private readonly array $source;

    /**
     * @param  array<string, mixed>  $options  Manifest options with the same keys as MediaSchemaAdopter's source
     */
    public function __construct(
        Authenticatable|SystemActor $actor,
        array $options,
        private readonly LegacyMediaGraph $graph,
    ) {
        parent::__construct($actor, $options);
        $this->source = LegacyMediaGraph::source($options);
    }

    public function connection(): Connection
    {
        return $this->graph->target();
    }

    public function authorize(string $phase): void
    {
        $this->graph->admit($this->actor, $this->source);
    }

    public function plan(): AdoptionPlan
    {
        $scan = $this->graph->scan($this->source);
        $origin = $this->graph->origin($this->source);
        $target = $this->connection();
        $source = ['connection' => $origin->getName(), 'database' => $origin->getDatabaseName(), 'prefix' => $origin->getTablePrefix()];
        $destination = ['connection' => $target->getName(), 'database' => $target->getDatabaseName(), 'prefix' => $target->getTablePrefix()];
        $steps = [];
        foreach (LegacyMediaGraph::Graph as $resource => [$legacy]) {
            $source[$resource] = $legacy;
            $destination[$resource] = $this->graph->table($resource);
            $steps[] = new AdoptionStep($resource, $resource === 'assets' ? [] : ['assets']);
        }

        return new AdoptionPlan(
            package: 'media',
            adapterVersion: self::Version,
            fingerprint: AdoptionPlan::digest(['mapping' => self::Version, 'source' => $scan['source_fingerprint'], 'options' => $this->options]),
            source: $source,
            destination: $destination,
            optionsDigest: AdoptionPlan::digest($this->options),
            steps: $steps,
            expected: $scan['source'],
            collisions: $scan['conflicts'],
        );
    }

    public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch
    {
        $origin = $this->graph->origin($this->source);
        /** @var list<stdClass> $legacyRows */
        $legacyRows = $step->page($origin->table(LegacyMediaGraph::Graph[$step->id][0])->useWritePdo(), $cursor, $limit + 1)->get()->all();
        $page = array_slice($legacyRows, 0, $limit);
        $rows = [];
        foreach ($this->graph->mapRows($origin, $step->id, $page, $this->source) as ['row' => $row, 'error' => $error]) {
            $rows[] = $row ?? throw new AdoptionException('Media adoption cannot map '.$error);
        }
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new AdoptionBatch($step->id, $cursor, $last === null ? $cursor : $step->cursorFrom($last), $rows, count($legacyRows) <= $limit);
    }

    /**
     * Insert missing rows, keep matching staged rows and refuse any staged row that differs from its source.
     */
    public function writeBatch(AdoptionBatch $batch): ?string
    {
        $target = $this->connection();
        $table = $this->graph->table($batch->step);
        $existing = $target->table($table)->useWritePdo()->whereIn('id', array_column($batch->rows, 'id'))->get()->keyBy('id');
        $pending = [];
        foreach ($batch->rows as $row) {
            $id = is_string($row['id'] ?? null) ? $row['id'] : throw new AdoptionException("Media adoption batch for [{$batch->step}] has a row without a string ID.");
            $current = $existing->get($id);
            if ($current === null) {
                $pending[] = $row;
            } elseif (! $this->graph->matches($row, $current)) {
                throw new AdoptionException("Media adoption drift: {$table} [{$id}] differs from its legacy row.");
            }
        }
        foreach (array_chunk($pending, 30) as $chunk) {
            $target->table($table)->insert($chunk);
        }

        return null;
    }

    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        $scan = $this->graph->scan($this->source);

        return new AdoptionVerification($scan['missing'], $scan['extra'], $scan['changed'], $scan['target_digest'], $scan['problems']);
    }
}
