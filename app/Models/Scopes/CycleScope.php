<?php

namespace App\Models\Scopes;

use App\Domain\Cycles\CurrentCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains a model to the cycle the request is operating in.
 *
 * Deliberately inert until a cycle has been pinned on CurrentCycle, so domain
 * services and tests that address several cycles at once keep working unscoped.
 * The web stack pins the cycle in SetCurrentCycle middleware, which is what makes
 * this scope active for every user-facing query.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class CycleScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentCycle::class);

        if (! $current->isPinned()) {
            return;
        }

        $builder->where($model->qualifyColumn('cycle_id'), $current->id());
    }
}
