<?php

namespace App\Domain\Governance;

use App\Enums\CommitteeRole;
use App\Enums\MemberStatus;
use App\Models\CommitteeTerm;
use App\Models\Cycle;

/**
 * What the next committee would look like if the constitution's succession were followed.
 *
 * It proposes and nothing more. The deputy moves up — Vice-Chairperson to Chairperson,
 * Vice-Treasurer to Treasurer — and the seats that vacates are left open for the group
 * to nominate into. Nobody is appointed by this class; the committee reads the
 * proposal at the share-out meeting and confirms it office by office, because a
 * succession that happened automatically would be an election nobody held.
 *
 * A term is one year, so the sitting Chairperson and Treasurer are shown as stepping
 * down rather than continuing.
 */
class SuccessionPlanner
{
    public function __construct(protected CommitteeTermService $terms) {}

    /**
     * The proposal, one entry per office.
     *
     * @return array<int, array{
     *     role: string,
     *     role_label: string,
     *     incumbent_member_id: int|null,
     *     incumbent_name: string|null,
     *     proposed_member_id: int|null,
     *     proposed_name: string|null,
     *     source_role: string|null,
     *     rationale: string,
     *     needs_nomination: bool,
     * }>
     */
    public function proposeFor(Cycle $cycle): array
    {
        $serving = $this->terms->current($cycle)
            ->filter(fn (CommitteeTerm $term): bool => $term->role !== CommitteeRole::Signatory)
            ->keyBy(fn (CommitteeTerm $term): string => $term->role->value);

        $proposals = [];

        foreach ([
            CommitteeRole::Chairperson,
            CommitteeRole::ViceChairperson,
            CommitteeRole::Treasurer,
            CommitteeRole::ViceTreasurer,
        ] as $office) {
            $incumbent = $serving->get($office->value)?->member;

            $deputy = collect(CommitteeRole::cases())
                ->first(fn (CommitteeRole $role): bool => $role->succeedsTo() === $office);

            $successor = $deputy === null ? null : $serving->get($deputy->value)?->member;

            /* A deputy who has left, died or been expelled cannot be moved up. */
            if ($successor !== null && $successor->status !== MemberStatus::Active) {
                $successor = null;
            }

            $proposals[] = [
                'role' => $office->value,
                'role_label' => $office->label(),
                'incumbent_member_id' => $incumbent?->id,
                'incumbent_name' => $incumbent?->full_name,
                'proposed_member_id' => $successor?->id,
                'proposed_name' => $successor?->full_name,
                'source_role' => $successor === null ? null : $deputy?->value,
                'rationale' => $this->rationale($office, $deputy, $successor !== null, $incumbent !== null),
                'needs_nomination' => $successor === null,
            ];
        }

        return $proposals;
    }

    protected function rationale(
        CommitteeRole $office,
        ?CommitteeRole $deputy,
        bool $hasSuccessor,
        bool $hasIncumbent,
    ): string {
        if ($hasSuccessor && $deputy !== null) {
            return sprintf(
                'The %s steps up. The sitting %s has served their year.',
                $deputy->label(),
                $office->label(),
            );
        }

        if ($deputy !== null) {
            return sprintf(
                'No %s is serving, so this office has to be nominated into.',
                $deputy->label(),
            );
        }

        return $hasIncumbent
            ? 'This office has no deputy to move up; the group nominates a replacement.'
            : 'Vacant. The group nominates.';
    }
}
