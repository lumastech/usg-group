<?php

namespace App\Domain\Support;

use App\Models\Member;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every money mutation in the application runs through here.
 *
 * It wraps the operation in a database transaction and writes a single activity-log
 * entry naming the actor, the reason and the result, so the group always has an
 * answer to "who moved this money, when, and what did it change".
 */
class MoneyMutator
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function mutate(Member $actor, string $reason, Closure $operation, array $context = []): mixed
    {
        return DB::transaction(function () use ($actor, $reason, $operation, $context) {
            $result = $operation();

            activity('money')
                ->causedBy($actor->user)
                ->performedOn($result instanceof Model ? $result : $actor)
                ->withProperties($context + [
                    'actor_member_id' => $actor->id,
                    'actor_name' => $actor->full_name,
                ])
                ->event('money.mutated')
                ->log($reason);

            return $result;
        });
    }
}
