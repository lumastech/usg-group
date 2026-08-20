<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Governance\CommitteeRoleSync;
use App\Enums\CommitteeRole;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use Illuminate\Console\Command;

/**
 * Brings the portal's committee roles back in line with the terms on record.
 *
 * Nothing should need this in normal use — CommitteeTermService grants and revokes in
 * the same transaction as the term itself. It exists for the cases that bypass that:
 * an import, a restore from backup, or a role somebody granted by hand in tinker
 * before this module existed. Running it is safe and idempotent.
 */
class SyncCommitteeRoles extends Command
{
    protected $signature = 'unity:sync-committee-roles
        {--cycle= : Cycle id to reconcile, defaulting to the current cycle}';

    protected $description = 'Reconcile committee portal roles with the terms in committee_terms';

    public function handle(CommitteeRoleSync $sync, CurrentCycle $currentCycle): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find((int) $this->option('cycle'))
            : $currentCycle->get();

        if ($cycle === null) {
            $this->error('No cycle to reconcile.');

            return self::FAILURE;
        }

        $count = $sync->syncCycle($cycle);

        $this->info("Reconciled {$count} members of {$cycle->name}.");

        $serving = CommitteeTerm::query()
            ->forCycle($cycle->id)
            ->current()
            ->with('member.user')
            ->get();

        if ($serving->isEmpty()) {
            $this->line('No committee terms are being served.');

            return self::SUCCESS;
        }

        $this->table(
            ['Office', 'Member', 'Portal role', 'Login'],
            $serving->map(fn (CommitteeTerm $term): array => [
                $term->role->label(),
                $term->member->full_name,
                $term->role->portalRole()?->value ?? '—',
                $term->member->user === null ? 'not invited' : 'yes',
            ])->all(),
        );

        $unmapped = $serving->filter(
            fn (CommitteeTerm $term): bool => $term->role === CommitteeRole::Signatory,
        );

        if ($unmapped->isNotEmpty()) {
            $this->comment("{$unmapped->count()} signatory term(s) carry no portal role by design.");
        }

        return self::SUCCESS;
    }
}
