<?php

declare(strict_types=1);

namespace Nvl\Media\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Internal byte-exact expression comparing wrapped text columns or one value.
 */
final readonly class ExactMediaText implements Expression
{
    public function __construct(
        private string $left,
        private string $right,
        private string $driver,
        private bool $valueComparison = false,
    ) {}

    /**
     * Compare one grammar-wrapped column with two bound copies of a value.
     */
    public static function value(string $column, string $driver): self
    {
        return new self($column, '?', $driver, true);
    }

    /**
     * Cast one grammar-wrapped identifier column to text.
     */
    public static function text(string $column): string
    {
        return "CAST({$column} AS TEXT)";
    }

    /** Return byte-exact SQL for the active grammar. */
    public function getValue(Grammar $grammar): string
    {
        if ($this->valueComparison) {
            return match ($this->driver) {
                'pgsql' => "{$this->left} COLLATE \"C\" = CAST(? AS TEXT) COLLATE \"C\" ".
                    "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH(CAST(? AS TEXT))",
                default => "{$this->left} = ? COLLATE BINARY ".
                    "AND LENGTH(CAST({$this->left} AS BLOB)) = LENGTH(CAST(? AS BLOB))",
            };
        }

        return match ($this->driver) {
            'pgsql' => "{$this->left} COLLATE \"C\" = {$this->right} COLLATE \"C\" ".
                "AND OCTET_LENGTH({$this->left}) = OCTET_LENGTH({$this->right})",
            default => "{$this->left} = {$this->right} COLLATE BINARY ".
                "AND LENGTH(CAST({$this->left} AS BLOB)) = ".
                "LENGTH(CAST({$this->right} AS BLOB))",
        };
    }
}
