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

function AipForm({ initial, offices, editingId }: { initial: AipData; offices: Office[]; editingId: number | null }) {
    const form = useForm<AipData>(initial);
    const totalCents = amounts.reduce((total, field) => {
        const value = Number(form.data[field.key]);
        return total + (Number.isFinite(value) ? Math.round(value * 100) : 0);
    }, 0);

    return (
        <section className="bg-card rounded-xl border border-t-4 border-t-blue-600 p-5 shadow-sm">
            <h2 className="text-lg font-semibold">{editingId ? 'Edit AIP' : 'Create AIP draft'}</h2>
            <p className="text-muted-foreground mt-1 text-sm">
                Fiscal year {initial.fiscal_year}. Saving a draft does not reserve your office budget.
            </p>
            <form
                className="mt-5 space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (editingId) {
                        form.put(`/aips/${editingId}`, { preserveScroll: true });
                    } else {
                        form.post('/aips', { preserveScroll: true, onSuccess: () => form.reset() });
                    }
                }}
            >
                <div>
                    <label htmlFor="aip-office" className="text-sm font-medium">
                        Office
                    </label>
                    <select
                        id="aip-office"
                        className={fieldClass}
                        value={form.data.office_id}
                        disabled={editingId !== null}
                        required
                        onChange={(event) => form.setData('office_id', event.target.value)}
                    >
                        <option value="">Select an office</option>
                        {offices.map((office) => (
                            <option key={office.id} value={office.id}>
                                {office.code} — {office.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.office_id} className="mt-1" />
                    <InputError message={form.errors.fiscal_year} className="mt-1" />
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    {fields.map((field) => (
                        <div key={field.key}>
                            <label htmlFor={`aip-${field.key}`} className="text-sm font-medium">
                                {field.label}
                            </label>
                            <input
                                id={`aip-${field.key}`}
                                type={field.type}
                                className={fieldClass}
                                value={form.data[field.key]}
                                required
                                maxLength={'maxLength' in field ? field.maxLength : undefined}
                                min={field.type === 'date' ? `${initial.fiscal_year}-01-01` : undefined}
                                max={field.type === 'date' ? `${initial.fiscal_year}-12-31` : undefined}
                                onChange={(event) => form.setData(field.key, event.target.value)}
                            />
                            <InputError message={form.errors[field.key]} className="mt-1" />
                        </div>
                    ))}
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    {(
                        [
                            { key: 'description', label: 'Description' },
                            { key: 'expected_output', label: 'Expected output' },
                        ] as const
                    ).map((field) => (
                        <div key={field.key}>
                            <label htmlFor={`aip-${field.key}`} className="text-sm font-medium">
                                {field.label}
                            </label>
                            <textarea
                                id={`aip-${field.key}`}
                                className={fieldClass}
                                rows={3}
                                maxLength={5000}
                                value={form.data[field.key]}
                                onChange={(event) => form.setData(field.key, event.target.value)}
                            />
                            <InputError message={form.errors[field.key]} className="mt-1" />
                        </div>
                    ))}
                </div>
                <fieldset className="rounded-xl border p-4">
                    <legend className="px-2 text-sm font-semibold">Proposed amounts in PHP</legend>
                    <div className="grid gap-4 md:grid-cols-2">
                        {amounts.map((field) => (
                            <div key={field.key}>
                                <label htmlFor={`aip-${field.key}`} className="text-sm font-medium">
                                    {field.label}
                                </label>
                                <input
                                    id={`aip-${field.key}`}
                                    type="number"
                                    min="0"
                                    max="10000000000"
                                    step="0.01"
                                    required
                                    className={fieldClass}
                                    value={form.data[field.key]}
                                    onChange={(event) => form.setData(field.key, event.target.value)}
                                />
                                <InputError message={form.errors[field.key]} className="mt-1" />
                            </div>
                        ))}
                    </div>
                    <p className="mt-5 rounded-lg bg-blue-50 p-4 text-lg font-semibold text-blue-900">Proposed total: {money(totalCents)}</p>
                </fieldset>
                <InputError message={form.errors.workflow} />
                <InputError message={form.errors.version} />
                <div className="flex flex-wrap items-center gap-4">
                    <button type="submit" className={buttonClass} disabled={form.processing}>
                        {form.processing ? 'Saving...' : editingId ? 'Save changes' : 'Save draft'}
                    </button>
                    {editingId && (
                        <Link href={`/aips?year=${initial.fiscal_year}`} className="text-sm font-medium text-blue-700">
                            Cancel editing
                        </Link>
                    )}
                </div>
            </form>
        </section>
    );
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
                {canWrite && offices.length > 0 && (
                    <AipForm key={`${editingId ?? 'new'}-${initial.version}-${year}`} initial={initial} editingId={editingId} offices={offices} />
                )}
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
                    <div className="flex items-center justify-between border-t p-4 text-sm">
                        <span>
                            Page {plans.current_page} of {plans.last_page}
                        </span>
                        <div className="flex gap-4">
                            {plans.prev_page_url && (
                                <Link href={plans.prev_page_url} className="text-blue-700">
                                    Previous
                                </Link>
                            )}
                            {plans.next_page_url && (
                                <Link href={plans.next_page_url} className="text-blue-700">
                                    Next
                                </Link>
                            )}
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
