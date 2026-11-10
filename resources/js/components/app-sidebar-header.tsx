import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CalendarDays, Landmark } from 'lucide-react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const page = usePage<SharedData>();

    if (['aips', 'ppas', 'aip-summary'].includes(page.component)) {
        const today = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'long' }).format(new Date());
        return (
            <header className="flex min-h-[68px] items-center justify-between gap-3 border-b border-blue-100 bg-[#f2f6fc] px-3 text-[#102e50] md:px-5">
                <div className="flex items-center gap-3">
                    <SidebarTrigger aria-label="Toggle navigation" />
                    <span className="grid size-10 place-items-center rounded-full border-2 border-blue-200 bg-white text-blue-800">
                        <Landmark className="size-6" />
                    </span>
                    <div>
                        <p className="leading-tight font-bold">Municipality of Talavera</p>
                        <p className="text-xs text-slate-600">Nueva Ecija</p>
                    </div>
                </div>
                <div className="hidden items-center gap-2 rounded-lg border border-blue-100 bg-white px-3 py-2 text-xs font-semibold sm:flex">
                    <CalendarDays className="size-4 text-blue-700" /> {today}
                </div>
            </header>
        );
    }

    if (page.component === 'ppmp') {
        return (
            <header className="flex min-h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-2 text-slate-900 md:px-6">
                <div className="flex min-w-0 items-center gap-3">
                    <SidebarTrigger className="shrink-0 text-slate-700 hover:bg-slate-100 hover:text-slate-900" />
                    <div className="min-w-0">
                        <p className="text-xl leading-tight font-extrabold tracking-tight text-blue-950">ePPRISM</p>
                        <p className="hidden text-xs text-slate-600 sm:block">
                            Electronic Planning, Procurement, Resource &amp; Inventory Management System
                        </p>
                    </div>
                </div>
                <span className="max-w-40 truncate text-sm font-semibold text-slate-700" title={page.props.auth.user.name}>
                    {page.props.auth.user.name}
                </span>
            </header>
        );
    }

    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
