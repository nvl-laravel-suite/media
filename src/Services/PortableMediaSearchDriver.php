<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use InvalidArgumentException;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Models\Media;
use PDO;
use Pdo\Sqlite;

/**
 * Portable substring and JSON search for moderate media datasets.
 *
 * @internal
 */
final class PortableMediaSearchDriver implements MediaSearchDriver
{
    /**
     * @param  Builder<Media>  $query
     */
    public function apply(Builder $query, string $search): void
    {
        $model = $query->getModel();
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $filename = $grammar->wrap($model->qualifyColumn('filename'));
        $type = $grammar->wrap($model->qualifyColumn('type'));
        $tags = $model->qualifyColumn('tags');
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        if ($grammar instanceof PostgresGrammar) {
            $filenameLike = "({$filename} COLLATE pg_c_utf8) ILIKE ? ESCAPE '!'";
            $typeLike = "({$type} COLLATE pg_c_utf8) ILIKE ? ESCAPE '!'";
        } else {
            $caseFunction = 'lower';
            if ($connection->getDriverName() === 'sqlite') {
                $this->registerSqliteCaseFoldFunction($connection);
                $caseFunction = 'nvl_media_casefold';
            }
            $filenameLike = "{$caseFunction}({$filename}) like {$caseFunction}(?) escape '!'";
            $typeLike = "{$caseFunction}({$type}) like {$caseFunction}(?) escape '!'";
        }
        $this->assertSafeSearchSql($filenameLike);
        $this->assertSafeSearchSql($typeLike);

        $query->where(function (Builder $builder) use ($filenameLike, $typeLike, $tags, $pattern, $search): void {
            $builder->whereRaw($filenameLike, [$pattern])
                ->orWhereRaw($typeLike, [$pattern])
                ->orWhereJsonContains($tags, $search);
        });
    }

    /** Register Unicode case folding on both PDOs used by the SQLite query. */
    private function registerSqliteCaseFoldFunction(Connection $connection): void
    {
        $fold = static fn (?string $value): ?string => $value === null ? null : mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
        $writePdo = $connection->getPdo();
        $readPdo = $connection->getReadPdo();

        foreach ($writePdo === $readPdo ? [$writePdo] : [$writePdo, $readPdo] as $pdo) {
            if ($pdo instanceof Sqlite) {
                $pdo->createFunction('nvl_media_casefold', $fold, 1, Sqlite::DETERMINISTIC);
            } elseif ($pdo instanceof PDO) {
                $pdo->sqliteCreateFunction('nvl_media_casefold', $fold, 1, PDO::SQLITE_DETERMINISTIC);
            }
        }
    }

    /**
     * @phpstan-assert literal-string $sql
     */
    private function assertSafeSearchSql(string $sql): void
    {
        if (preg_match("/^(?:(lower|nvl_media_casefold)\\([A-Za-z0-9_.\"`\\[\\]]+\\) like \\1\\(\\?\\) escape|\\([A-Za-z0-9_.\"`\\[\\]]+ COLLATE pg_c_utf8\\) ILIKE \\? ESCAPE) '!'$/D", $sql) !== 1) {
            throw new InvalidArgumentException('Media search columns produced unsafe SQL.');
        }
    }
}
