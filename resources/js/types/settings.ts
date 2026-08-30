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
