import { Link, usePage } from '@inertiajs/react';
import {
    BookUser,
    FolderGit2,
    LayoutGrid,
    PanelsTopLeft,
    ScrollText,
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
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem, User } from '@/types';

const sharedNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];
const adminNavItems: NavItem[] = [
    {
        title: 'Students',
        href: '/students',
        icon: Users,
    },
    {
        title: 'Teachers',
        href: '/teachers',
        icon: BookUser,
    },
    {
        title: 'Guardians',
        href: '/guardians',
        icon: Users,
    },
    {
        title: 'School Setup',
        href: '/setup',
        icon: FolderGit2,
    },
    {
        title: 'Review Results',
        href: '/setup/review/results',
        icon: PanelsTopLeft,
    },
    {
        title: 'Activity Log',
        href: '/activity-logs',
        icon: ScrollText,
    },
];

const guardianNavItems: NavItem[] = [
    {
        title: 'My Dashboard',
        href: '/parent/dashboard',
        icon: LayoutGrid,
    },
];

const footerNavItems: NavItem[] = [
    // {
    //     title: 'Repository',
    //     href: 'https://github.com/laravel/react-starter-kit',
    //     icon: FolderGit2,
    // },
    // {
    //     title: 'Documentation',
    //     href: 'https://laravel.com/docs/starter-kits#react',
    //     icon: BookOpen,
    // },
];

export function AppSidebar() {
    const { auth }: { auth: { roles: string[]; user: User } } = usePage<{
        auth: { roles: string[] };
    }>().props;
    const role = auth.roles[0];
    const teacherNavItems: NavItem[] = [];
    const teacher = auth.user.teacher;

    if (teacher) {
        teacherNavItems.push({
            title: 'My Subjects',
            href: `/setup/teacher/${teacher.uuid}`,
            icon: BookUser,
        });
    }

    const roleMap: Record<string, NavItem[]> = {
        admin: adminNavItems,
        head_of_school: adminNavItems,
        teacher: teacherNavItems,
        guardian: guardianNavItems,
    };
    const mainNavItems = useMemo(() => {
        const roleItems = roleMap[role] ?? [];

        return [...roleItems, ...sharedNavItems];
    }, [role]);

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

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
