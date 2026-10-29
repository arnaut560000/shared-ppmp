import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

type Office = { id: number; code: string; name: string };

type AipData = {
    office_id: string;
    fiscal_year: string;
    reference: string;
    title: string;
    description: string;
    fund_source: string;
    expected_output: string;
    start_date: string;
    end_date: string;
    ps: string;
    mooe: string;
    fe: string;
    co: string;
    version: string;
    workflow: string;
};

type PlanRow = {
    id: number;
    reference: string;
    title: string;
    office: string | null;
    status: string;
    total_cents: number;
    can_edit: boolean;
};

type Props = {
    year: number;
    canWrite: boolean;
    offices: Office[];
    plans: {
        data: PlanRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    editingId: number | null;
    initial: AipData;
    message: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Annual investment plans', href: '/aips' }];
const fieldClass =
    'mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60';
const buttonClass = 'rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50';

const amounts = [
    { key: 'ps', label: 'Personal Services (PS)' },
    { key: 'mooe', label: 'Maintenance and Other Operating Expenses (MOOE)' },
    { key: 'fe', label: 'Financial Expenses (FE)' },
    { key: 'co', label: 'Capital Outlay (CO)' },
] as const;

const fields = [
    { key: 'reference', label: 'AIP reference', type: 'text', maxLength: 100 },
    { key: 'title', label: 'Program / project / activity title', type: 'text', maxLength: 255 },
    { key: 'fund_source', label: 'Fund source', type: 'text', maxLength: 255 },
    { key: 'start_date', label: 'Start date', type: 'date' },
    { key: 'end_date', label: 'End date', type: 'date' },
] as const;

const statusStyles: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700',
    submitted: 'bg-yellow-100 text-yellow-900',
    approved: 'bg-blue-100 text-blue-800',
    returned: 'bg-red-100 text-red-800',
};

function money(cents: number): string {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
}

export default function Aips({ year, canWrite, offices, plans, editingId, initial, message }: Props) {
    const currentYear = new Date().getFullYear();
    const years = Array.from(new Set([year, currentYear - 1, currentYear, currentYear + 1, currentYear + 2])).sort((a, b) => a - b);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Annual investment plans" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-blue-700">DIGITALavera ePPRISM</p>
                        <h1 className="mt-1 text-2xl font-bold">Annual Investment Plans</h1>
                        <p className="text-muted-foreground mt-2 text-sm">Prepare your office programs, projects, and activities.</p>
                    </div>
                    <div>
                        <label htmlFor="aip-year" className="text-sm font-medium">
                            Fiscal year
                        </label>
                        <select
                            id="aip-year"
                            className={fieldClass}
                            value={year}
                            disabled={editingId !== null}
                            onChange={(event) => router.get('/aips', { year: Number(event.target.value) })}
                        >
                            {years.map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                    </div>
                </header>
                <section className="bg-card overflow-hidden rounded-xl border border-t-4 border-t-yellow-400 shadow-sm">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">AIPs for {year}</h2>
                        <p className="text-muted-foreground mt-1 text-sm">{plans.total} document(s)</p>
                    </div>
                    {plans.data.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-sm">No AIPs found for this fiscal year.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-blue-50 text-blue-900">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">
                                            Reference / Title
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Office
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-5 py-3 text-right">
                                            Amount
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {plans.data.map((plan) => (
                                        <tr key={plan.id} className="border-t">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold">{plan.reference}</p>
                                                <p className="text-muted-foreground">{plan.title}</p>
                                            </td>
                                            <td className="px-5 py-4">{plan.office}</td>
                                            <td className="px-5 py-4">
                                                <span
                                                    className={`rounded-full px-2 py-1 text-xs font-medium capitalize ${statusStyles[plan.status] ?? 'bg-slate-100 text-slate-700'}`}
                                                >
                                                    {plan.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-right whitespace-nowrap">{money(plan.total_cents)}</td>
                                            <td className="px-5 py-4">
                                                {plan.can_edit ? (
                                                    <Link href={`/aips/${plan.id}/edit`} className="font-medium text-blue-700 underline">
                                                        Edit
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">View only</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
