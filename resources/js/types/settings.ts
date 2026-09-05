/** The cycle calendar screen: the dates every window in the portal is read from. */

export type CalendarCycle = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    starts_on: string;
    ends_on: string;
    weekend_policy_label: string;
};

/** The five dates a month may be re-dated on, in the shape the form posts them. */
export type CycleMonthSchedule = {
    /** Local datetime, `YYYY-MM-DDTHH:mm`, as a datetime-local input reads it. */
    declarations_open_at: string;
    declarations_close_at: string;
    trading_starts_on: string;
    trading_concludes_on: string;
    disbursement_on: string;
};

export type CalendarMonth = CycleMonthSchedule & {
    id: number;
    sequence: number;
    label: string;
    month: string;
    status: string;
    /** DeclarationWindow's state today: before_declarations, declarations, between, trading, closed. */
    window: string;
    is_current: boolean;
    /** False once the month has been traded and closed — its dates are then history. */
    editable: boolean;
};

export type CalendarConstitution = {
    declarations_open_day: number;
    declarations_open_hour: number;
    declarations_close_day: number;
    trading_start_day: number;
    disbursement_day: number;
};

/** The roles screen: the bundles of permissions, and what may be done to each. */

export type RoleAbilities = {
    /** False for the administrator, whose bundle is every permission by definition. */
    editPermissions: boolean;
    rename: boolean;
    delete: boolean;
    /** An office that has been re-scoped and can be put back on the constitution's bundle. */
    reset: boolean;
};

export type ManagedRole = {
    id: number;
    /** The handle code matches on, e.g. `vice_treasurer`. */
    name: string;
    label: string;
    description: string | null;
    /** One of the constitution's offices: it may be re-scoped, never renamed or deleted. */
    is_system: boolean;
    /** An office whose bundle the committee changed here, so the seeder leaves it alone. */
    customised: boolean;
    /** How many logins hold this role — everyone who loses it if it is deleted. */
    holders: number;
    permissions: string[];
    abilities: RoleAbilities;
};

export type PermissionOption = {
    name: string;
    label: string;
};

/** Permissions offered by the section of the portal they belong to, e.g. "Loans". */
export type PermissionGroup = {
    key: string;
    label: string;
    permissions: PermissionOption[];
};
