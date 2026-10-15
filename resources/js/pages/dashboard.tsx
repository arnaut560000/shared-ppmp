import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

type DashboardProps = {
    year: number;
    account: {
        name: string;
        role: string;
        office: string | null;
        needsOffice: boolean;
    };
    stats: {
        ceiling: number;
        reserved: number;
        available: number;
        counts: {
            draft: number;
            submitted: number;
            approved: number;
            returned: number;
        };
    };
    recent: {
        id: number;
        reference: string;
        title: string;
        type: string;
        status: string;
        office: string;
        total_cents: number;
    }[];
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Planning overview',
        href: '/dashboard',
    },
];

const roleLabels: Record<string, string> = {
    admin: 'System Administrator',
    office: 'Department / Office',
    budget: 'Budget Office',
    mayor: 'Mayor / Approving Authority',
    procurement: 'BAC / Procurement Office',
    gso: 'General Services Office',
    inventory: 'Supply / Inventory Office',
};

const statusStyles: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700',
    submitted: 'bg-amber-100 text-amber-800',
    approved: 'bg-emerald-100 text-emerald-800',
    returned: 'bg-rose-100 text-rose-800',
};

function money(cents: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(cents / 100);
}

export default function Dashboard({
    year,
    account,
    stats,
    recent,
}: DashboardProps) {
    const currentYear = new Date().getFullYear();

    const years = Array.from(
        new Set([year, currentYear - 1, currentYear, currentYear + 1, currentYear + 2]),
    ).sort((a, b) => a - b);

    const cards = [
        {
            label: 'Budget ceiling',
            value: money(stats.ceiling),
            detail: 'Total allocation for the selected year',
        },
        {
            label: 'AIP allocations',
            value: money(stats.reserved),
            detail: 'Submitted and approved AIPs',
        },
        {
            label: 'Available for planning',
            value: money(stats.available),
            detail: 'Budget ceiling minus AIP allocations',
        },
        {
            label: 'Awaiting review',
            value: String(stats.counts.submitted),
            detail: 'Submitted AIP and PPMP documents',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Planning overview" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                            DIGITALavera ePPRISM
                        </p>
                        <h1 className="mt-1 text-2xl font-bold">
                            Planning overview
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Welcome, {account.name}.{' '}
                            {roleLabels[account.role] ?? account.role}
                            {account.office ? ` · ${account.office}` : ''}
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <label htmlFor="fiscal-year" className="text-sm font-medium">
                            Fiscal year
                        </label>
                        <select
                            id="fiscal-year"
                            value={year}
                            onChange={(event) =>
                                router.get(
                                    '/dashboard',
                                    { year: Number(event.target.value) },
                                    { preserveScroll: true },
                                )
                            }
                            className="rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            {years.map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                    </div>
                </header>

                {account.needsOffice && (
                    <div
                        role="status"
                        className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
                    >
                        Your account has no assigned office yet. An administrator
                        must assign your office before you can access its plans
                        and budget.
                    </div>
                )}

                <section className="rounded-2xl bg-emerald-950 p-6 text-white md:p-8">
                    <p className="text-sm font-medium text-emerald-200">
                        FISCAL YEAR {year}
                    </p>
                    <h2 className="mt-3 text-2xl font-semibold md:text-3xl">
                        From a good plan to meaningful progress.
                    </h2>
                    <p className="mt-3 max-w-2xl text-sm leading-6 text-emerald-100">
                        Keep your office requirements connected to their budget,
                        planning documents, and approval status.
                    </p>
                </section>

                <section
                    aria-label="Planning totals"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {cards.map((card) => (
                        <article key={card.label} className="rounded-xl border p-5">
                            <h2 className="text-sm text-muted-foreground">
                                {card.label}
                            </h2>
                            <p className="mt-3 text-2xl font-bold">{card.value}</p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {card.detail}
                            </p>
                        </article>
                    ))}
                </section>

                <section className="rounded-xl border">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">Recent plans</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Your most recently updated AIP and PPMP documents.
                        </p>
                    </div>

                    {recent.length === 0 ? (
                        <div className="p-8 text-center">
                            <p className="font-medium">No plans for {year}</p>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Existing plans for this year will appear here.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">Reference</th>
                                        <th scope="col" className="px-5 py-3">Plan</th>
                                        <th scope="col" className="px-5 py-3">Office</th>
                                        <th scope="col" className="px-5 py-3">Type</th>
                                        <th scope="col" className="px-5 py-3">Status</th>
                                        <th scope="col" className="px-5 py-3 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent.map((plan) => (
                                        <tr key={plan.id} className="border-t">
                                            <td className="px-5 py-4 font-medium">
                                                {plan.reference}
                                            </td>
                                            <td className="px-5 py-4">{plan.title}</td>
                                            <td className="px-5 py-4">{plan.office}</td>
                                            <td className="px-5 py-4 uppercase">{plan.type}</td>
                                            <td className="px-5 py-4">
                                                <span
                                                    className={`rounded-full px-2 py-1 text-xs font-medium capitalize ${
                                                        statusStyles[plan.status]
                                                        ?? 'bg-slate-100 text-slate-700'
                                                    }`}
                                                >
                                                    {plan.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-right whitespace-nowrap">
                                                {money(plan.total_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    aria-label="Document status totals"
                    className="grid gap-4 sm:grid-cols-3"
                >
                    {[
                        { label: 'Draft documents', count: stats.counts.draft },
                        { label: 'Approved documents', count: stats.counts.approved },
                        { label: 'Returned documents', count: stats.counts.returned },
                    ].map((item) => (
                        <div key={item.label} className="rounded-xl border p-5">
                            <p className="text-sm text-muted-foreground">{item.label}</p>
                            <p className="mt-2 text-2xl font-semibold">{item.count}</p>
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}