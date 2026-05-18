import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup } from '@/types';

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup key={index} className={`px-2 py-0 ${index > 0 ? 'mt-4' : ''}`}>
                    {group.label && (
                        <SidebarGroupLabel className="mb-1 text-xs font-semibold uppercase tracking-wider text-sidebar-foreground/50">
                            {group.label}
                        </SidebarGroupLabel>
                    )}
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {group.items.map((item) => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl(item.href)}
                                        tooltip={{ children: item.title }}
                                        className="group/item"
                                    >
                                        <Link href={item.href} prefetch>
                                            {item.icon && (
                                                <item.icon className="h-4 w-4 shrink-0" />
                                            )}
                                            <span className="truncate">
                                                {item.title}
                                            </span>
                                        </Link>
                                    </SidebarMenuButton>
                                    {item.badge !== undefined && (
                                        <SidebarMenuBadge>
                                            {item.badge}
                                        </SidebarMenuBadge>
                                    )}
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            ))}
        </>
    );
}
