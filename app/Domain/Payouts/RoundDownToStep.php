<?php

namespace App\Domain\Payouts;

use App\Support\Kwacha;

/**
 * Shaves a net value down to a whole multiple of a note denomination.
 *
 * Not bound today — NoRounding is. It exists so that when the group does settle on a
 * convention ("pay in whole K50s, the odd kwacha stays with the fund") the change is
 * one line in AppServiceProvider, with the behaviour already written and tested.
 *
 * A member under water is left alone: rounding a debt would quietly change what
 * somebody owes, which is not the same act as shaving a payment down to notes.
 */
class RoundDownToStep implements RoundingPolicy
{
    /** @param  int  $stepNgwee  the denomination to round down to, e.g. 5_000 for K50 */
    public function __construct(protected int $stepNgwee) {}

    public function adjustmentFor(int $netValueNgwee): int
    {
        if ($this->stepNgwee <= 1 || $netValueNgwee <= 0) {
            return 0;
        }

        return -($netValueNgwee % $this->stepNgwee);
    }

    public function describe(): string
    {
        return 'Rounded down to the nearest '.Kwacha::format($this->stepNgwee)
            .'; the difference stays with the group';
    }
}
