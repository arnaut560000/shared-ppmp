import { SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem, useSidebar } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, ClipboardList } from 'lucide-react';
import { useId, useState } from 'react';

type PlanningProps = SharedData & {
    year?: number;
    ppa?: { fiscal_year: number };
};

export default function AipManagementNav() {
    const { component, props } = usePage<PlanningProps>();
    const isPlanning = ['aips', 'ppas', 'ppmp', 'my-ppmps', 'aip-summary'].includes(component);
    const [expanded, setExpanded] = useState(isPlanning);
    const { state, setOpen, isMobile, setOpenMobile } = useSidebar();
    const submenuId = useId();
    const isOffice = props.auth.user.role === 'office';
    const year = props.year ?? (component === 'ppmp' ? props.ppa?.fiscal_year : undefined);
    const isGso = props.auth.user.role === 'gso';
    const myPpmpsActive = (isOffice || isGso) && (component === 'my-ppmps' || component === 'ppmp');
    const links = [
        {
            label: isOffice ? 'My AIP' : 'Department AIPs',
            href: year ? `/aips?year=${year}` : '/aips',
            active: isPlanning && !myPpmpsActive,
        },
    ];

    if ((isOffice && props.auth.user.office_id) || isGso) {
        const path = isGso ? '/ppmp-reviews' : '/my-ppmps';
        links.push({
            label: isGso ? 'PPMPs for GSO review' : 'My PPMPs',
            href: year ? `${path}?year=${year}` : path,
            active: myPpmpsActive,
        });
    }

    const rail = !isMobile && state === 'collapsed';
    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                type="button"
                tooltip="AIP Management"
                aria-label="AIP Management"
                aria-expanded={expanded && !rail}
                aria-controls={submenuId}
                isActive={isPlanning}
                onClick={() => {
                    if (rail) {
                        setOpen(true);
                        setExpanded(true);
                    } else setExpanded((open) => !open);
                }}
            >
                <ClipboardList aria-hidden="true" />
                <span>AIP Management</span>
                <ChevronDown
                    aria-hidden="true"
                    className={
                        'ml-auto size-4 group-data-[collapsible=icon]:hidden motion-safe:transition-transform ' + (expanded ? '' : '-rotate-90')
                    }
                />
            </SidebarMenuButton>
            <div id={submenuId} hidden={!expanded || rail}>
                <SidebarMenuSub>
                    {links.map((link) => (
                        <SidebarMenuSubItem key={link.label}>
                            <SidebarMenuSubButton asChild isActive={link.active}>
                                <Link
                                    href={link.href}
                                    aria-current={link.active ? 'page' : undefined}
                                    onSuccess={() => {
                                        if (isMobile) setOpenMobile(false);
                                    }}
                                >
                                    {link.label}
                                </Link>
                            </SidebarMenuSubButton>
                        </SidebarMenuSubItem>
                    ))}
                </SidebarMenuSub>
            </div>
        </SidebarMenuItem>
    );
}
