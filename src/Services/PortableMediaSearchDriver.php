<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use InvalidArgumentException;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Models\Media;

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
        $grammar = $model->getConnection()->getQueryGrammar();
        $filename = $grammar->wrap($model->qualifyColumn('filename'));
        $type = $grammar->wrap($model->qualifyColumn('type'));
        $tags = $model->qualifyColumn('tags');

        if ($grammar instanceof PostgresGrammar) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $filenameIlike = "({$filename} COLLATE pg_c_utf8) ILIKE ? ESCAPE '!'";
            $typeIlike = "({$type} COLLATE pg_c_utf8) ILIKE ? ESCAPE '!'";
            $this->assertSafeSearchSql($filenameIlike);
            $this->assertSafeSearchSql($typeIlike);
            $query->where(function (Builder $builder) use ($filenameIlike, $typeIlike, $tags, $pattern, $search): void {
                $builder->whereRaw($filenameIlike, [$pattern])
                    ->orWhereRaw($typeIlike, [$pattern])
                    ->orWhereJsonContains($tags, $search);
            });

            return;
        }

        $variants = array_values(array_unique([
            $search,
            mb_strtolower($search, 'UTF-8'),
            mb_strtoupper($search, 'UTF-8'),
            mb_convert_case($search, MB_CASE_TITLE, 'UTF-8'),
        ]));
        $patterns = array_map(
            static fn (string $variant): string => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $variant).'%',
            $variants,
        );
        $filenameLike = "lower({$filename}) like lower(?) escape '!'";
        $typeLike = "lower({$type}) like lower(?) escape '!'";
        $this->assertSafeSearchSql($filenameLike);
        $this->assertSafeSearchSql($typeLike);

        $query->where(function (Builder $builder) use ($filenameLike, $typeLike, $tags, $patterns, $search): void {
            foreach ($patterns as $index => $pattern) {
                if ($index === 0) {
                    $builder->whereRaw($filenameLike, [$pattern]);
                } else {
                    $builder->orWhereRaw($filenameLike, [$pattern]);
                }
                $builder->orWhereRaw($typeLike, [$pattern]);
            }
            $builder->orWhereJsonContains($tags, $search);
        });
    }

    /**
     * @phpstan-assert literal-string $sql
     */
    private function assertSafeSearchSql(string $sql): void
    {
        if (preg_match("/^(?:lower\\([A-Za-z0-9_.\"`\\[\\]]+\\)|\\([A-Za-z0-9_.\"`\\[\\]]+ COLLATE pg_c_utf8\\)) (?:ILIKE \\? ESCAPE|like lower\\(\\?\\) escape) '!'$/D", $sql) !== 1) {
            throw new InvalidArgumentException('Media search columns produced unsafe SQL.');
        }
    }
}
