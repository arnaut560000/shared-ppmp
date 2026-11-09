import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const page = usePage<SharedData>();

    if (['aips', 'ppas', 'ppmp', 'aip-summary'].includes(page.component)) {
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
