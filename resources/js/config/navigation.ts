import {
    Banknote,
    ChartPie,
    ClipboardList,
    Coins,
    Handshake,
    FileText,
    Gavel,
    HandCoins,
    HeartHandshake,
    House,
    LayoutGrid,
    PiggyBank,
    ShieldCheck,
    Users,
    Wallet,
} from '@lucide/vue';

import type { Component } from 'vue';
import type { PermissionName } from '@/types/auth';

/**
 * The single source of navigation for both portals.
 *
 * Each item declares the permissions it needs; the committee sidebar, the member
 * bottom-nav and the mobile drawer all render from this one config, so a new
 * section appears everywhere it should by adding a single entry here.
 */
export type NavItem = {
    title: string;
    href: string;
    icon: Component;
    /** Item shows when the user holds ANY of these. Omitted means always visible. */
    permissions?: PermissionName[];
    /** Require every listed permission instead of any one of them. */
    requireAll?: boolean;
    /** Treat child routes as active too, e.g. /app/loans/12 keeps "Loans" lit. */
    exact?: boolean;
};

export type NavSection = {
    label: string;
    items: NavItem[];
};

/** Committee portal at /app — every section is permission-gated. */
export const adminNavigation: NavSection[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', href: '/app', icon: LayoutGrid, exact: true },
            {
                title: 'Reports',
                href: '/app/reports',
                icon: ChartPie,
                permissions: ['reports.view'],
            },
        ],
    },
    {
        label: 'Savings',
        items: [
            {
                title: 'Declarations',
                href: '/app/declarations',
                icon: ClipboardList,
                permissions: ['declarations.view'],
            },
            {
                title: 'Trading',
                href: '/app/trading',
                icon: Handshake,
                permissions: ['trading.operate', 'declarations.view'],
            },
            {
                title: 'Savings ledger',
                href: '/app/savings',
                icon: PiggyBank,
                permissions: ['savings.view'],
            },
        ],
    },
    {
        label: 'Lending',
        items: [
            {
                title: 'Loans',
                href: '/app/loans',
                icon: HandCoins,
                permissions: ['loans.view'],
            },
            {
                title: 'Disbursements',
                href: '/app/loans/queue',
                icon: Banknote,
                permissions: ['loans.disburse'],
            },
            {
                title: 'Social fund',
                href: '/app/fund',
                icon: HeartHandshake,
                permissions: [
                    'fund.view',
                    'fund.record',
                    'fund.approve-outflow',
                ],
            },
            {
                title: 'Payouts',
                href: '/app/payouts',
                icon: Coins,
                permissions: ['payouts.approve', 'payouts.execute'],
            },
        ],
    },
    {
        label: 'Group',
        items: [
            {
                title: 'Members',
                href: '/app/members',
                icon: Users,
                permissions: ['members.view', 'members.manage'],
            },
            {
                title: 'Governance',
                href: '/app/governance',
                icon: Gavel,
                permissions: ['governance.record'],
            },
            {
                title: 'Cycle',
                href: '/app/cycle',
                icon: ShieldCheck,
                permissions: ['cycles.manage'],
            },
        ],
    },
];

/** Member portal at /my — this drives a bottom-nav, so keep it to five items. */
export const memberNavigation: NavItem[] = [
    { title: 'Home', href: '/my', icon: House, exact: true },
    {
        title: 'Declare',
        href: '/my/declarations',
        icon: ClipboardList,
        permissions: ['declarations.submit-own'],
    },
    { title: 'Savings', href: '/my/savings', icon: Wallet },
    { title: 'Loans', href: '/my/loan', icon: HandCoins },
    { title: 'Fund', href: '/my/fund', icon: HeartHandshake },
];
