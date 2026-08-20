<?php

use App\Domain\Governance\VotingThreshold;

/**
 * The one arithmetic decision the constitution leaves open.
 *
 * Sixty percent of an awkward number is a fraction, and rounding it the wrong way
 * quietly lowers the bar the group wrote down. The group settled on the ceiling with
 * a `>=` comparison: never pass on less than sixty percent, but an exact sixty is
 * enough.
 */
beforeEach(function () {
    $this->threshold = new VotingThreshold;
});

it('rounds a fractional requirement up', function (int $base, int $needed) {
    expect($this->threshold->needed($base))->toBe($needed);
})->with([
    'the group as constituted' => [30, 18],
    'one short, the spec\'s example' => [29, 18],
    'twenty-eight' => [28, 17],
    'twenty-six rounds up from 15.6' => [26, 16],
    'twenty-five is exact' => [25, 15],
    'a handful' => [7, 5],
    'a pair' => [2, 2],
    'one' => [1, 1],
]);

it('never lets a motion carry on less than sixty percent', function () {
    /* 17 of 29 is 58.6%. The constitution says sixty. */
    expect($this->threshold->isMet(17, 29))->toBeFalse()
        ->and($this->threshold->isMet(18, 29))->toBeTrue();
});

it('treats exactly sixty percent as carrying', function () {
    expect($this->threshold->isMet(18, 30))->toBeTrue()
        ->and($this->threshold->isMet(17, 30))->toBeFalse();
});

it('refuses to find a majority in an empty room', function () {
    expect($this->threshold->needed(0))->toBe(0)
        ->and($this->threshold->isMet(0, 0))->toBeFalse();
});

it('reads the arithmetic back in words', function () {
    expect($this->threshold->explain(30, 'total active members'))
        ->toBe('18 of 30 total active members');
});
