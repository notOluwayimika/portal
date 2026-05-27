import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    ClipboardCopyIcon,
    ClipboardList,
    GraduationCap,
    History,
    LayoutDashboard,
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
                title: 'Head of Schools',
                href: '/setup/head-of-schools',
                icon: GraduationCap,
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

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { auth }: { auth: { roles: string[]; user: User } } = usePage<{
        auth: { roles: string[] };
    }>().props;
    const roles = auth.roles;

    const navGroups = useMemo(() => {
        const groups: NavGroup[] = [dashboardGroup];

        if (roles.includes('guardian')) {
            groups.push(...guardianNavGroups);
        }

        if (roles.includes('head_of_school')) {
            groups.push(...headOfSchoolNavGroups);
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

        if (roles.includes('admin')) {
            groups.push(...adminNavGroups);
        }

        return groups;
    }, [roles, auth.user.teacher]);

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
