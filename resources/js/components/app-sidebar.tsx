import AipManagementNav from '@/components/aip-management-nav';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, Package, Settings } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    const role = auth.user.role;
    const inventoryLabel = role === 'office' ? 'My Office Inventory' : role === 'procurement' ? 'Receiving Records' : 'Inventory';

    const navigation: NavItem[] = [
        ...(typeof role === 'string' && ['inventory', 'gso', 'admin', 'office', 'procurement'].includes(role)
            ? [{ title: inventoryLabel, url: '/inventory', icon: Package }]
            : []),
        ...(auth.user.role === 'mayor'
            ? [{ title: 'PPMPs for approval', url: '/ppmp-approvals', icon: ClipboardList }]
            : []),
        ...(auth.user.role === 'gso' || auth.user.role === 'admin'
            ? [{ title: 'Consolidated PPMP', url: '/consolidated-ppmp', icon: ClipboardList }]
            : []),
        ...(auth.user.role === 'admin' || auth.user.role === 'budget'
            ? [{ title: 'Office budget allocations', url: '/budgets', icon: ClipboardList }]
            : []),
        ...(auth.user.role === 'admin'
            ? [
                  {
                      title: 'Administration',
                      url: '/administration',
                      icon: Settings,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/aips" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navigation}>
                    <AipManagementNav />
                </NavMain>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
