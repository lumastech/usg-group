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

    /**
     * The same, for a mutation no person initiated.
     *
     * Interest charges and the daily late penalty are posted by the scheduler on the
     * trading date, so there is no causer to name — but the entry still belongs in the
     * money log, attributed to the system.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function system(string $reason, Closure $operation, array $context = []): mixed
    {
        return DB::transaction(function () use ($reason, $operation, $context) {
            $result = $operation();

            $logger = activity('money')
                ->withProperties($context + ['actor_name' => 'System'])
                ->event('money.mutated');

            if ($result instanceof Model) {
                $logger->performedOn($result);
            }

            $logger->log($reason);

            return $result;
        });
    }
}
