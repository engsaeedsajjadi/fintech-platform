<?php

declare(strict_types=1);

namespace App\Domain\Ledger\ValueObjects;

/**
 * Typed bcmath wrappers for money arithmetic.
 *
 * Plain bcmath functions take `numeric-string`, but most money values in
 * this codebase are carried as plain `string` (Eloquent decimal casts,
 * config values, JSON input) and the repo runs PHPStan at level 8 with
 * `treatPhpDocTypesAsCertain: false`, so phpdoc `numeric-string` claims
 * do not satisfy the analyzer. These wrappers accept plain strings,
 * assert numeric content (validated upstream by MoneyAmount /
 * DecimalString at the boundary), and return plain strings — keeping
 * every callsite level-8 clean without sprinkling asserts everywhere.
 *
 * Behaviour is identical to the wrapped bcmath call: same scale
 * semantics, truncation, and comparison results.
 */
final class DecimalMath
{
    private function __construct()
    {
    }

    public static function add(string $a, string $b, int $scale): string
    {
        assert(is_numeric($a) && is_numeric($b));

        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale): string
    {
        assert(is_numeric($a) && is_numeric($b));

        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale): string
    {
        assert(is_numeric($a) && is_numeric($b));

        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale): string
    {
        assert(is_numeric($a) && is_numeric($b));

        return bcdiv($a, $b, $scale);
    }

    /** @return -1|0|1 */
    public static function cmp(string $a, string $b, int $scale): int
    {
        assert(is_numeric($a) && is_numeric($b));

        return bccomp($a, $b, $scale);
    }
}
