import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    BookOpen,
    Building2,
    ClipboardCopyIcon,
    ClipboardList,
    FileSpreadsheet,
    Landmark,
    GraduationCap,
    Heart,
    History,
    Layers,
    LayoutDashboard,
    MessageSquare,
    PenTool,
    Percent,
    ReceiptText,
    RefreshCw,
    Shield,
    ShieldCheck,
    UserCog,
    Users,
    Wallet,
} from 'lucide-react';
import { useMemo } from 'react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { dashboard } from '@/routes';
import type { NavGroup, NavItem, User } from '@/types';
import type { Teacher } from '@/types/models';

const dashboardGroup: NavGroup = {
    items: [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutDashboard,
        },
    ],
};

const headOfSchoolNavGroups: NavGroup[] = [
    {
        label: 'Head of School',
        items: [
            {
                title: 'Review Results',
                href: '/setup/review/results',
                icon: ClipboardList,
            },
            {
                title: 'Pending Results',
                href: '/setup/review/pending',
                icon: ClipboardCopyIcon,
            },
            {
                title: 'Incomplete Results',
                href: '/results/incomplete',
                icon: AlertTriangle,
            },
            {
                title: 'Student Comments',
                href: '/head-of-school/comments',
                icon: MessageSquare,
            },
            {
                title: 'Outstanding Comments',
                href: '/outstanding-comments',
                icon: AlertTriangle,
            },
            {
                title: 'Result Signature',
                href: '/result-signature',
                icon: PenTool,
            },
        ],
    },
    {
        label: 'Reports',
        items: [
            {
                title: 'Results per Class',
                href: '/reports/results-per-class',
                icon: ClipboardList,
            },
            {
                title: 'Broadsheets',
                href: '/reports/broadsheets',
                icon: FileSpreadsheet,
            },
        ],
    },
];

const boardingParentNavGroups: NavGroup[] = [
    {
        label: 'Boarding Parent',
        items: [
            {
                title: 'Behavioral Assessments',
                href: '/boarding-parent/behavioral-assessments',
                icon: Heart,
            },
        ],
    },
];

const keyStageCoordinatorNavGroups: NavGroup[] = [
    {
        label: 'Key Stage Coordinator',
        items: [
            {
                title: 'Student Comments',
                href: '/key-stage-coordinator/comments',
                icon: MessageSquare,
            },
        ],
    },
];

const formTeacherNavGroups: NavGroup[] = [
    {
        label: 'Form Teacher',
        items: [
            {
                title: 'Student Comments',
                href: '/form-teacher/comments',
                icon: MessageSquare,
            },
        ],
    },
];

const principalNavGroups: NavGroup[] = [
    {
        label: 'Principal',
        items: [
            {
                title: 'Students',
                href: '/students',
                icon: GraduationCap,
            },
            {
                title: 'Results per Class',
                href: '/reports/results-per-class',
                icon: ClipboardList,
            },
            {
                title: 'Pending Results',
                href: '/setup/review/pending',
                icon: ClipboardCopyIcon,
            },
            {
                title: 'Incomplete Results',
                href: '/results/incomplete',
                icon: AlertTriangle,
            },
            {
                title: 'Outstanding Comments',
                href: '/outstanding-comments',
                icon: AlertTriangle,
            },
            {
                title: 'Broadsheets',
                href: '/reports/broadsheets',
                icon: FileSpreadsheet,
            },
            {
                title: 'Result Signature',
                href: '/result-signature',
                icon: PenTool,
            },
        ],
    },
];

const adminNavGroups: NavGroup[] = [
    {
        label: 'People',
        items: [
            {
                title: 'Students',
                href: '/students',
                icon: GraduationCap,
            },
            {
                title: 'Teachers',
                href: '/teachers',
                icon: UserCog,
            },
            {
                title: 'Guardians',
                href: '/guardians',
                icon: Users,
            },
        ],
    },
    {
        label: 'Academic',
        items: [
            {
                title: 'School Setup',
                href: '/setup',
                icon: Building2,
            },
            {
                title: 'Review Results',
                href: '/setup/review/results',
                icon: ClipboardList,
            },
            {
                title: 'Pending Results',
                href: '/setup/review/pending',
                icon: ClipboardCopyIcon,
            },
            {
                title: 'Incomplete Results',
                href: '/results/incomplete',
                icon: AlertTriangle,
            },
            {
                title: 'Head of Schools',
                href: '/setup/head-of-schools',
                icon: GraduationCap,
            },
            {
                title: 'Principals',
                href: '/setup/principals',
                icon: Shield,
            },
            {
                title: 'Teacher Assignments',
                href: '/setup/teacher-assignments',
                icon: Shield,
            },
            {
                title: 'CCM Curricula',
                href: '/setup/curricula-ccm',
                icon: RefreshCw,
            },
            {
                title: 'Backfill Past Terms',
                href: '/setup/curricula-backfill',
                icon: History,
            },
            {
                title: 'Outstanding Comments',
                href: '/outstanding-comments',
                icon: AlertTriangle,
            },
            {
                title: 'Notices',
                href: '/notices',
                icon: Bell,
            },
        ],
    },
    {
        label: 'Reports',
        items: [
            {
                title: 'Results per Class',
                href: '/reports/results-per-class',
                icon: ClipboardList,
            },
            {
                title: 'Broadsheets',
                href: '/reports/broadsheets',
                icon: FileSpreadsheet,
            },
        ],
    },
    {
        label: 'System',
        items: [
            {
                title: 'Activity Log',
                href: '/activity-logs',
                icon: History,
            },
        ],
    },
];

const guardianNavGroups: NavGroup[] = [
    {
        items: [
            // {
            //     title: 'My Dashboard',
            //     href: '/parent/dashboard',
            //     icon: LayoutDashboard,
            // },
            {
                title: 'My Wards',
                href: '/parent/wards',
                icon: Users,
            },
        ],
    },
];

const superAdminNavGroups: NavGroup[] = [
    {
        label: 'Super Admin',
        items: [
            {
                title: 'Schools',
                href: '/super-admin/schools',
                icon: Building2,
            },
            {
                title: 'Role Permissions',
                href: '/super-admin/rbac',
                icon: Shield,
            },
            {
                title: 'Admins',
                href: '/super-admin/admins',
                icon: Shield,
            },
        ],
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const {
        auth,
    }: {
        auth: {
            roles: string[];
            user: User;
            isSuperAdmin?: boolean;
            school?: unknown;
        };
    } = usePage<{
        auth: { roles: string[] };
    }>().props;
    const roles = auth.roles;
    const isSuperAdmin = !!auth.isSuperAdmin;
    const hasSchoolContext = !!auth.school;
    const { can, permissions } = usePermissions();

    const navGroups = useMemo(() => {
        // Super admin without a school context only sees the management area.
        if (isSuperAdmin && !hasSchoolContext) {
            return superAdminNavGroups;
        }

        const groups: NavGroup[] = [dashboardGroup];

        // The /super-admin management area is the one deliberately role-gated
        // surface (role:super_admin, kept by C2); no permission stands behind
        // it, so it stays keyed on the role.
        if (isSuperAdmin) {
            groups.push(...superAdminNavGroups);
        }

        // The admin working area is an AUTHORIZATION statement, so it keys on the
        // effective permission the write routes carry (C2: admin_area.access) —
        // held by admin AND super_admin (bypass), not by principal. This folds
        // the old isSuperAdmin/roles.includes('admin') special-cases into one
        // check with no visibility change. Persona menus below stay role-driven:
        // they are identity presentation, and super_admin's effective-everything
        // would otherwise flood the sidebar with every persona (c4-brief D2).
        if (can('admin_area.access')) {
            groups.push(...adminNavGroups);
        }

        // C5: the Users module carries its OWN permission, so its nav item
        // gates on that permission — not on admin_area.access — the same
        // compose-by-permission pattern Finance's nav additions follow (I7).
        if (can('rbac.manage_users')) {
            groups.push({
                label: 'Administration',
                items: [
                    {
                        title: 'Users',
                        href: '/setup/users',
                        icon: UserCog,
                    },
                ],
            });
        }

        // FINANCE — a MODULE, not a persona, so it sits after the admin working area
        // and before the persona menus, and every item gates on the permission its
        // own route carries. Same compose-by-permission pattern as the Users item
        // above (C5), which the comment there already names for exactly this.
        //
        // THIS IS NOT THE CASE use-permissions.ts WARNS ABOUT. That docblock says not
        // to gate sidebar PERSONA menus on effective permissions, because super_admin's
        // effective set is ~everything and would surface every persona at once
        // (c4-brief D2). A permission-gated module is the opposite situation: the
        // question "may this user reach this screen" is precisely what the route asks,
        // so asking it here is what keeps the menu and the route from disagreeing.
        //
        // The group itself keys on `finance.access` because the whole /finance route
        // group requires it — an item shown to someone without it would 403 on click.
        if (can('finance.access')) {
            const financeItems: NavItem[] = [
                { title: 'Accounts', href: '/finance', icon: Wallet },
            ];

            // THE CHECKER PREDICATE IS DERIVED, NEVER LISTED. /finance/approvals is
            // gated server-side on a list built from Permission::cases() — every
            // finance ability whose terminal segment makes it a checker action — so a
            // future `finance.refund.approve` joins that route the day the case exists,
            // with no edit. A hard-coded array here would silently hide the item from
            // that future checker while the route happily admitted them: the same
            // defect approval-feeds.ts was written to kill, rebuilt in the nav. So the
            // same predicate is applied to the user's own effective set.
            const isFinanceChecker = [...permissions].some(
                (ability) =>
                    ability.startsWith('finance.') &&
                    (ability.endsWith('.approve') ||
                        ability.endsWith('.reject')),
            );

            if (isFinanceChecker) {
                financeItems.push({
                    title: 'Approvals',
                    href: '/finance/approvals',
                    icon: ShieldCheck,
                });
            }

            // ADR 0040 makes this correct rather than lucky: `approve`/`reject` are
            // excluded from the super-admin Gate::before bypass, and EffectivePermissions
            // resolves through the Gate, so a super_admin's shared set holds NO finance
            // checker ability and this item is hidden from them — which is right, because
            // the route would refuse them too.
            if (can('finance.opening-balance.submit')) {
                financeItems.push({
                    title: 'Opening balances',
                    href: '/finance/opening-balances/import',
                    icon: FileSpreadsheet,
                });
            }

            // Bulk invoice runs (U6) — bill a whole cohort. Keyed on `finance.invoice.generate`,
            // the ability its route and all four of its API routes carry, and the SAME one the
            // single-student generate already uses: bulk raises the same document under the same
            // rule, so nothing new was coined for it. This is an ACT rather than configuration,
            // which is why it sits beside Opening balances rather than with the config items below.
            if (can('finance.invoice.generate')) {
                financeItems.push({
                    title: 'Bulk invoice runs',
                    href: '/finance/bulk-invoice-runs',
                    icon: Layers,
                });
            }

            // Bank accounts (S6/U3) — finance CONFIGURATION, so it keys on its own
            // finance.bank-account.manage rather than on the group's finance.access. Everyone who
            // can view finance must not be offered a screen that configures where money lands, and
            // the route carries the same permission so a visible item can never 403 on click.
            if (can('finance.bank-account.manage')) {
                financeItems.push({
                    title: 'Bank accounts',
                    href: '/finance/bank-accounts',
                    icon: Landmark,
                });
            }

            // Fee schedules (U1) — finance CONFIGURATION beside Bank accounts, and it keys on its
            // own finance.fee-schedule.manage for the reason the comment above already gives: this
            // screen SETS PRICES, so everyone who can view finance must not be offered it, and the
            // route carries the same ability so a visible item can never 403 on click.
            if (can('finance.fee-schedule.manage')) {
                financeItems.push({
                    title: 'Fee schedules',
                    href: '/finance/fee-schedules',
                    icon: ReceiptText,
                });
            }

            // Discount policies (U2) — finance CONFIGURATION beside Fee schedules, keyed on the same
            // ability its route carries. There is no separate `manage` ability for this catalog: the
            // page and every control on it post the one endpoint gated on change.submit, so this item
            // and the route ask exactly the same question and a visible entry cannot 403 on click.
            if (can('finance.discount-policy.change.submit')) {
                financeItems.push({
                    title: 'Discount policies',
                    href: '/finance/discount-policies',
                    icon: Percent,
                });
            }

            groups.push({ label: 'Finance', items: financeItems });
        }

        if (roles.includes('guardian')) {
            groups.push(...guardianNavGroups);
        }

        if (roles.includes('head_of_school')) {
            groups.push(...headOfSchoolNavGroups);
        }

        if (roles.includes('boarding_parent')) {
            groups.push(...boardingParentNavGroups);
        }

        if (roles.includes('key_stage_coordinator')) {
            groups.push(...keyStageCoordinatorNavGroups);
        }

        if (roles.includes('form_teacher')) {
            groups.push(...formTeacherNavGroups);
        }

        if (roles.includes('principal')) {
            groups.push(...principalNavGroups);
        }

        if (roles.includes('teacher')) {
            const teacher = auth.user.teacher as Teacher | undefined;

            if (teacher) {
                groups.push({
                    label: 'Teaching',
                    items: [
                        {
                            title: 'My Subjects',
                            href: `/setup/teacher/${teacher.uuid}`,
                            icon: BookOpen,
                        },
                    ],
                });
            }
        }

        return groups;
    }, [
        roles,
        auth.user.teacher,
        isSuperAdmin,
        hasSchoolContext,
        can,
        permissions,
    ]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarSeparator className="bg-white/20" />

            <SidebarContent className="gap-0 pt-3">
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
