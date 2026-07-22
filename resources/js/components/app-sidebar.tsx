import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    BookOpen,
    Building2,
    ClipboardCopyIcon,
    ClipboardList,
    FileSpreadsheet,
    GraduationCap,
    Heart,
    History,
    LayoutDashboard,
    MessageSquare,
    PenTool,
    RefreshCw,
    Shield,
    UserCog,
    Users,
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
    const { can } = usePermissions();

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

        if (roles.includes('guardian')) {
            groups.push(...guardianNavGroups);
        }

        if (roles.includes('head_of_school')) {
            groups.push(...headOfSchoolNavGroups);
        }

        if (roles.includes('boarding_parent')) {
            groups.push(...boardingParentNavGroups);
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
    }, [roles, auth.user.teacher, isSuperAdmin, hasSchoolContext, can]);

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
