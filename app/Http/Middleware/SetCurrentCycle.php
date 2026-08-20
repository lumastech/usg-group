<?php

namespace App\Http\Middleware;

use App\Domain\Cycles\CurrentCycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the active cycle for the request.
 *
 * Pinning is what activates App\Models\Scopes\CycleScope, so from here on every
 * query against a cycle-scoped model is confined to this cycle unless it opts out.
 */
class SetCurrentCycle
{
    public function __construct(protected CurrentCycle $currentCycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentCycle->set($this->currentCycle->get());

        return $next($request);
    }
}
