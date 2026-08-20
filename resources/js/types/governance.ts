import type {
    CommitteeRole,
    MeetingType,
    MotionType,
    TermEndReason,
    ThresholdBasis,
} from './enums';

/** One member's spell in one office. */
export type CommitteeTerm = {
    id: number;
    member_id: number;
    member_name?: string;
    member_number?: number;
    role: CommitteeRole;
    role_label: string;
    /** Null for a signatory, whose authority is at the bank rather than in the portal. */
    portal_role: string | null;
    started_at: string;
    ended_at: string | null;
    end_reason: TermEndReason | null;
    end_reason_label: string | null;
    resignation_notice_date: string | null;
    /** A month after notice — the earliest a resignation may take effect. */
    earliest_resignation_date: string | null;
    notice_waiver_note: string | null;
    is_current: boolean;
    expires_on: string;
    is_overdue: boolean;
    abilities: { end: boolean };
};

/** One line of the succession proposal. It appoints nobody. */
export type SuccessionProposal = {
    role: CommitteeRole;
    role_label: string;
    incumbent_member_id: number | null;
    incumbent_name: string | null;
    proposed_member_id: number | null;
    proposed_name: string | null;
    source_role: CommitteeRole | null;
    rationale: string;
    needs_nomination: boolean;
};

export type CommitteeRoleOption = {
    value: CommitteeRole;
    label: string;
    portal_role: string | null;
};

/** The live quorum count for one meeting. */
export type Quorum = {
    present: number;
    active: number;
    needed: number;
    met: boolean;
    shortfall: number;
    explanation: string;
};

/** What deciding a motion would take right now. */
export type MotionRequirement = {
    basis: ThresholdBasis;
    basis_label: string;
    base: number;
    needed: number;
    explanation: string;
    quorum_met: boolean;
    can_decide: boolean;
    blocked_reason: string | null;
};

export type Motion = {
    id: number;
    meeting_id: number | null;
    type: MotionType;
    type_label: string;
    subject: string;
    target_member_id: number | null;
    target_name?: string | null;
    proposed_by_member_id: number;
    proposed_by_name?: string | null;
    votes_for: number;
    votes_against: number;
    abstentions: number;
    threshold_basis: ThresholdBasis;
    threshold_basis_label: string;
    /** As recorded on the day; null until the motion is decided. */
    eligible_count: number | null;
    votes_needed: number | null;
    passed: boolean | null;
    decided_at: string | null;
    is_decided: boolean;
    threshold_explanation: string | null;
    requirement: MotionRequirement;
    amendment?: {
        section_reference: string;
        current_text: string;
        proposed_text: string;
        effective_date: string;
    } | null;
    abilities: { decide: boolean };
};

export type MeetingRow = {
    id: number;
    meeting_date: string;
    type: MeetingType;
    type_label: string;
    subject: string | null;
    attendees_count: number;
    motions_count: number;
    quorum: Quorum;
};

export type MeetingDetail = {
    id: number;
    meeting_date: string;
    type: MeetingType;
    type_label: string;
    label: string;
    subject: string | null;
    notes: string | null;
};

/** One name on the attendance register. */
export type RollEntry = {
    id: number;
    member_number: number;
    full_name: string;
    is_present: boolean;
};

/** The six-month gate in front of the amendment form. */
export type AmendmentWindow = {
    is_open: boolean;
    opens_on: string;
    days_until_open: number;
    last_amended_on: string | null;
    last_amended_section: string | null;
};

export type Amendment = {
    id: number;
    motion_id: number;
    section_reference: string;
    current_text: string;
    proposed_text: string;
    effective_date: string;
    motion?: {
        id: number;
        subject: string;
        meeting_id: number | null;
        votes_for: number;
        votes_against: number;
        abstentions: number;
        eligible_count: number | null;
        votes_needed: number | null;
        threshold_explanation: string | null;
        passed: boolean | null;
        is_decided: boolean;
        decided_at: string | null;
    };
};

export type GovernanceCycle = { id: number; name: string };

export type MemberOption = {
    id: number;
    member_number: number;
    full_name: string;
};
