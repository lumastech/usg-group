<?php

namespace App\Domain\Payments;

/**
 * How much the name on an account looks like the name of the member being paid.
 *
 * This is a warning light, not a lock. Zambian accounts legitimately carry maiden
 * names, a middle name the group never recorded, an initial instead of a first name,
 * or a spouse's wallet — so a low score is shown to the people signing rather than used
 * to refuse the payment. What it catches is the case that matters: a destination
 * quietly changed to somebody else entirely.
 */
final class AccountNameMatcher
{
    /** Honorifics carry no identity and would otherwise inflate every score. */
    private const TITLES = ['MR', 'MRS', 'MS', 'MISS', 'DR', 'PROF', 'REV', 'SR', 'JR', 'III', 'II'];

    /** 0 to 100, where 100 is the same name. */
    public static function score(string $memberName, string $accountName): int
    {
        $left = self::tokens($memberName);
        $right = self::tokens($accountName);

        if ($left === [] || $right === []) {
            return 0;
        }

        if ($left === $right) {
            return 100;
        }

        $matched = 0;
        $remaining = $right;

        foreach ($left as $token) {
            foreach ($remaining as $index => $candidate) {
                if (self::sameToken($token, $candidate)) {
                    $matched++;
                    unset($remaining[$index]);

                    break;
                }
            }
        }

        return (int) round(100 * $matched / max(count($left), count($right)));
    }

    /** Whether the score is close enough not to trouble anybody with. */
    public static function isConfident(int $score): bool
    {
        return $score >= 80;
    }

    /**
     * Two name parts that mean the same person.
     *
     * An initial matches the name it stands for, which is what makes "C Mwansa" and
     * "Chanda Mwansa" the same person rather than a mismatch nobody would ever clear.
     */
    private static function sameToken(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (mb_strlen($left) === 1 || mb_strlen($right) === 1) {
            return mb_substr($left, 0, 1) === mb_substr($right, 0, 1);
        }

        return false;
    }

    /** @return array<int, string> */
    private static function tokens(string $name): array
    {
        $normalised = preg_replace('/[^A-Za-z ]/', ' ', $name) ?? '';

        $tokens = array_values(array_filter(
            array_map(
                fn (string $token): string => mb_strtoupper(trim($token)),
                preg_split('/\s+/', $normalised) ?: [],
            ),
            fn (string $token): bool => $token !== '' && ! in_array($token, self::TITLES, true),
        ));

        sort($tokens);

        return $tokens;
    }
}
