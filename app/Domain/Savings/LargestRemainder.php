<?php

namespace App\Domain\Savings;

/**
 * Splits a whole-ngwee amount proportionally without losing or inventing a ngwee.
 *
 * Each share first takes the floor of its exact value. The ngwee left over are handed
 * out one at a time, largest fractional part first, ties broken by the lower key so
 * the result is stable across runs. Rounding each share independently would not do:
 * it leaks ngwee out of the pool, or conjures them, and the month would not balance.
 */
final class LargestRemainder
{
    /**
     * @param  array<int, int>  $bases  key => basis
     * @return array<int, array{amount: int, residual: int}>
     */
    public static function split(array $bases, int $totalBasis, int $pool): array
    {
        $shares = [];
        $allocated = 0;

        foreach ($bases as $key => $basis) {
            $exact = $pool * $basis;
            $amount = intdiv($exact, $totalBasis);

            $shares[$key] = ['amount' => $amount, 'remainder' => $exact % $totalBasis, 'residual' => 0];
            $allocated += $amount;
        }

        $leftover = $pool - $allocated;

        $order = collect($shares)
            ->map(fn (array $share, int $key): array => $share + ['key' => $key])
            ->sortBy([['remainder', 'desc'], ['key', 'asc']])
            ->take(max(0, $leftover));

        foreach ($order as $share) {
            $shares[$share['key']]['amount']++;
            $shares[$share['key']]['residual'] = 1;
        }

        return array_map(
            fn (array $share): array => ['amount' => $share['amount'], 'residual' => $share['residual']],
            $shares,
        );
    }
}
