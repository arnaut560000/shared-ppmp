import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

type BudgetRow = {
    office_id: number;
    code: string;
    name: string;
    allocated: boolean;
    ceiling_cents: number;
    reserved_cents: number;
    available_cents: number;
};

type Props = {
    year: number;
    canManage: boolean;
    needsOffice: boolean;
    rows: BudgetRow[];
    message: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Office budgets', href: '/budgets' },
];

const fieldClass =
    'mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-blue-500';

function money(cents: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(cents / 100);
}

function BudgetForm({ year, rows }: { year: number; rows: BudgetRow[] }) {
    const form = useForm({
        office_id: '',
        fiscal_year: year,
        ceiling: '',
        comment: '',
    });

    const selected = rows.find(
        (row) => String(row.office_id) === form.data.office_id,
    );

    return (
        <section className="rounded-xl border border-t-4 border-t-blue-600 bg-card p-5 shadow-sm">
            <h2 className="text-lg font-semibold">
                Set office budget — FY {year}
            </h2>

            <p className="mt-1 text-sm text-muted-foreground">
                Enter the total annual ceiling. Saving replaces the current
                ceiling for this office and year.
            </p>

            {rows.length === 0 ? (
                <p className="mt-4 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-950">
                    Create an office in Administration before allocating a budget.
                </p>
            ) : (
                <form
                    className="mt-5 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();

                        form.post('/budgets', {
                            preserveScroll: true,
                            onSuccess: () => form.reset('comment'),
                        });
                    }}
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label htmlFor="budget-office" className="text-sm font-medium">
                                Office
                            </label>

                            <select
                                id="budget-office"
                                className={fieldClass}
                                value={form.data.office_id}
                                required
                                onChange={(event) => {
                                    const officeId = event.target.value;
                                    const row = rows.find(
                                        (item) => String(item.office_id) === officeId,
                                    );

                                    form.setData({
                                        ...form.data,
                                        office_id: officeId,
                                        ceiling: row?.allocated
                                            ? (row.ceiling_cents / 100).toFixed(2)
                                            : '',
                                        comment: '',
                                    });

                                    form.clearErrors();
                                }}
                            >
                                <option value="">Select an office</option>
                                {rows.map((row) => (
                                    <option key={row.office_id} value={row.office_id}>
                                        {row.code} — {row.name}
                                    </option>
                                ))}
                            </select>

                            <InputError message={form.errors.office_id} className="mt-1" />
                        </div>

                        <div>
                            <label htmlFor="budget-ceiling" className="text-sm font-medium">
                                Annual budget ceiling (PHP)
                            </label>

                            <input
                                id="budget-ceiling"
                                type="number"
                                min="0"
                                max="10000000000"
                                step="0.01"
                                required
                                className={fieldClass}
                                placeholder="Example: 500000.00"
                                value={form.data.ceiling}
                                onChange={(event) =>
                                    form.setData('ceiling', event.target.value)
                                }
                            />

                            <InputError message={form.errors.ceiling} className="mt-1" />
                        </div>
                    </div>

                    {selected && (
                        <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-3 text-sm text-yellow-950">
                            Current ceiling:{' '}
                            <strong>
                                {selected.allocated
                                    ? money(selected.ceiling_cents)
                                    : 'Not allocated'}
                            </strong>
                            {' · '}AIP allocations:{' '}
                            <strong>{money(selected.reserved_cents)}</strong>
                        </div>
                    )}

                    <div>
                        <label htmlFor="budget-comment" className="text-sm font-medium">
                            Reason for allocation or adjustment
                        </label>

                        <textarea
                            id="budget-comment"
                            className={fieldClass}
                            rows={3}
                            maxLength={1000}
                            required
                            placeholder="Example: Initial allocation for the fiscal year."
                            value={form.data.comment}
                            onChange={(event) =>
                                form.setData('comment', event.target.value)
                            }
                        />

                        <InputError message={form.errors.comment} className="mt-1" />
                    </div>

                    <InputError message={form.errors.fiscal_year} />

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {form.processing ? 'Saving...' : 'Save budget'}
                    </button>
                </form>
            )}
        </section>
    );
}

export default function Budgets({
    year,
    canManage,
    needsOffice,
    rows,
    message,
}: Props) {
    const currentYear = new Date().getFullYear();

    const years = Array.from(
        new Set([year, currentYear - 1, currentYear, currentYear + 1, currentYear + 2]),
    ).sort((a, b) => a - b);

    const totalCeiling = rows.reduce((total, row) => total + row.ceiling_cents, 0);
    const totalReserved = rows.reduce((total, row) => total + row.reserved_cents, 0);

    const summaries = [
        {
            label: 'Total budget ceiling',
            amount: totalCeiling,
            accent: 'border-t-blue-600',
        },
        {
            label: 'AIP allocations',
            amount: totalReserved,
            accent: 'border-t-red-500',
        },
        {
            label: 'Available for planning',
            amount: totalCeiling - totalReserved,
            accent: 'border-t-yellow-400',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Office budgets" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-blue-700">
                            DIGITALavera ePPRISM
                        </p>
                        <h1 className="mt-1 text-2xl font-bold">Office budgets</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Annual office ceilings and planning allocations.
                        </p>
                    </div>

                    <div>
                        <label htmlFor="budget-year" className="text-sm font-medium">
                            Fiscal year
                        </label>
                        <select
                            id="budget-year"
                            className={fieldClass}
                            value={year}
                            onChange={(event) =>
                                router.get('/budgets', {
                                    year: Number(event.target.value),
                                })
                            }
                        >
                            {years.map((value) => (
                                <option key={value} value={value}>{value}</option>
                            ))}
                        </select>
                    </div>
                </header>

                {message && (
                    <div
                        role="status"
                        className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900"
                    >
                        {message}
                    </div>
                )}

                {needsOffice && (
                    <div
                        role="status"
                        className="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-950"
                    >
                        Your account has no assigned office. Ask your administrator
                        to assign an office before viewing its budget.
                    </div>
                )}

                <section
                    aria-label="Budget totals"
                    className="grid gap-4 md:grid-cols-3"
                >
                    {summaries.map((summary) => (
                        <article
                            key={summary.label}
                            className={`rounded-xl border border-t-4 bg-card p-5 shadow-sm ${summary.accent}`}
                        >
                            <h2 className="text-sm text-muted-foreground">
                                {summary.label}
                            </h2>
                            <p className="mt-3 text-2xl font-bold">
                                {money(summary.amount)}
                            </p>
                        </article>
                    ))}
                </section>

                {canManage && <BudgetForm key={year} year={year} rows={rows} />}

                <section className="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">Office allocations — {year}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            AIP allocations include submitted and approved AIPs.
                            These amounts represent planning reservations, not actual spending.
                        </p>
                    </div>

                    {rows.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            No offices are available for this account.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-blue-50 text-blue-900">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">Office</th>
                                        <th scope="col" className="px-5 py-3">Status</th>
                                        <th scope="col" className="px-5 py-3 text-right">Ceiling</th>
                                        <th scope="col" className="px-5 py-3 text-right">AIP allocations</th>
                                        <th scope="col" className="px-5 py-3 text-right">Available</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.office_id} className="border-t">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold">{row.code}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.name}
                                                </p>
                                            </td>
                                            <td className="px-5 py-4">
                                                <span
                                                    className={`whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium ${
                                                        row.allocated
                                                            ? 'bg-blue-100 text-blue-800'
                                                            : 'bg-yellow-100 text-yellow-900'
                                                    }`}
                                                >
                                                    {row.allocated ? 'Allocated' : 'Not allocated'}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-right whitespace-nowrap">
                                                {money(row.ceiling_cents)}
                                            </td>
                                            <td className="px-5 py-4 text-right whitespace-nowrap">
                                                {money(row.reserved_cents)}
                                            </td>
                                            <td className="px-5 py-4 text-right font-semibold whitespace-nowrap">
                                                {money(row.available_cents)}
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