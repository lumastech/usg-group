import type {
    ExpulsionGround,
    MemberStatus,
    NextOfKinRelationship,
} from './enums';

/** A member's nominee, as sent by NextOfKinResource. */
export type NextOfKin = {
    id: number;
    name: string;
    phone: string | null;
    relationship: NextOfKinRelationship;
    relationship_label: string | null;
    relationship_display: string;
};

/**
 * One member, as sent by MemberResource. All money is integer ngwee.
 *
 * `abilities` are the policy's own answers for the signed-in user, so screens
 * render actions from them rather than re-deriving permissions on the client.
 */
export type Member = {
    id: number;
    member_number: number;
    full_name: string;
    nrc_number: string | null;
    phone: string | null;
    physical_address: string | null;
    is_diaspora: boolean;
    status: MemberStatus;
    status_label: string;
    status_reason: string | null;
    status_effective_on: string | null;
    status_changed_at: string | null;
    expulsion_ground: ExpulsionGround | null;
    expulsion_ground_label: string | null;
    date_of_death: string | null;
    joined_on: string;
    joining_month_sequence: number;
    joining_fee_ngwee: number;
    joining_fee_paid: boolean;
    has_login: boolean;
    email?: string | null;
    next_of_kin?: NextOfKin[];
    savings_ngwee: number | null;
    loan_balance_ngwee: number | null;
    abilities: MemberAbilities;
    [key: string]: unknown;
};

export type MemberAbilities = {
    view: boolean;
    update: boolean;
    changeStatus: boolean;
    invite: boolean;
};

/** One entry of the member's audit trail, rendered as the profile timeline. */
export type MemberActivity = {
    id: number;
    event: string | null;
    description: string;
    properties: Record<string, unknown>;
    causer: string | null;
    created_at: string | null;
};

/** Where the cycle stands relative to its registration window. */
export type RegistrationState = {
    open: boolean;
    closes_after_month: number | null;
    month_sequence: number | null;
    cycle_starts_on?: string;
    cycle_ends_on?: string;
    late_registration_month?: number;
    standard_fee_ngwee?: number;
    late_fee_ngwee?: number;
};

export type EnumOption = { value: string; label: string };

/** A next-of-kin row while it is being edited in the repeater. */
export type NextOfKinDraft = {
    name: string;
    phone: string;
    relationship: NextOfKinRelationship;
    relationship_label: string;
};

/**
 * The registration form's shape. `next_of_kin` is the repeater's rows.
 *
 * `email` is the address on the member's portal login rather than a column on the
 * member record, so it is only sent when correcting a member who has one.
 */
export type MemberFormData = {
    full_name: string;
    email?: string;
    nrc_number: string;
    phone: string;
    physical_address: string;
    is_diaspora: boolean;
    joined_on: string;
    joining_fee_ngwee: number | null;
    joining_fee_paid: boolean;
    next_of_kin: NextOfKinDraft[];
};
