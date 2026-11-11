import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Office = {
    id: number;
    code: string;
    name: string;
    ceiling_cents: number | null;
    allocated_cents: number;
    remaining_cents: number | null;
};

type Props = {
    year: number;
    offices: Office[];
    message: string | null;
};

const inputClass = 'mt-1 w-full rounded-lg border bg-background px-3 py-2';
const buttonClass = 'rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white disabled:opacity-50';
const money = (value: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value / 100);

export default function OfficeBudgets({ year, offices, message }: Props) {
    const [filterYear, setFilterYear] = useState(String(year));

    const first = offices[0];

    const form = useForm({
        office_id: first ? String(first.id) : '',
        fiscal_year: String(year),
        amount: first?.ceiling_cents == null ? '' : (first.ceiling_cents / 100).toFixed(2),
        expected_ceiling: first?.ceiling_cents == null ? '' : String(first.ceiling_cents),
        workflow: '',
    });

    function selectOffice(id: string) {
        const office = offices.find((row) => String(row.id) === id);

        form.setData({
            ...form.data,
            office_id: id,
            amount: office?.ceiling_cents == null ? '' : (office.ceiling_cents / 100).toFixed(2),
            expected_ceiling: office?.ceiling_cents == null ? '' : String(office.ceiling_cents),
        });

        form.clearErrors();
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Office budget allocations', href: '/budgets' }]}>
            <Head title="Office budget allocations" />

            <div className="space-y-6 p-6">
                <h1 className="text-2xl font-bold">Office budget allocations</h1>
                <p>Assign each department its budget for the fiscal year. PPA allocations include drafts.</p>

                <form
                    className="flex items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/budgets', { year: filterYear });
                    }}
                >
                    <label>
                        Fiscal year
                        <input
                            className={inputClass}
                            type="number"
                            min="2020"
                            max="2100"
                            required
                            value={filterYear}
                            onChange={(event) => setFilterYear(event.target.value)}
                        />
                    </label>
                    <button className={buttonClass}>Show</button>
                </form>

                {message && (
                    <p role="status" className="rounded-lg bg-blue-50 p-4 text-blue-950">
                        {message}
                    </p>
                )}

                {offices.length === 0 ? (
                    <p>An administrator must create an office first.</p>
                ) : (
                    <form
                        className="space-y-4 rounded-xl border border-t-4 border-t-blue-600 p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/budgets', { preserveState: 'errors', preserveScroll: true });
                        }}
                    >
                        <h2 className="font-semibold">Set office budget — {year}</h2>

                        <label className="block">
                            Department / Office
                            <select className={inputClass} value={form.data.office_id} onChange={(event) => selectOffice(event.target.value)}>
                                {offices.map((office) => (
                                    <option key={office.id} value={office.id}>
                                        {office.code} — {office.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            Total office budget in PHP
                            <input
                                className={inputClass}
                                type="number"
                                min="0"
                                max="10000000000"
                                step="0.01"
                                required
                                value={form.data.amount}
                                onChange={(event) => form.setData('amount', event.target.value)}
                            />
                        </label>

                        {Object.entries(form.errors).map(([key, error]) => (
                            <p key={key} role="alert" className="text-sm text-red-700">
                                {error}
                            </p>
                        ))}

                        <button className={buttonClass} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save office budget'}
                        </button>
                    </form>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-blue-50 text-blue-950">
                            <tr>
                                <th className="p-3">Office</th>
                                <th className="p-3">Budget</th>
                                <th className="p-3">Allocated to PPAs</th>
                                <th className="p-3">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            {offices.map((office) => (
                                <tr key={office.id} className="border-t">
                                    <td className="p-3">
                                        {office.code} — {office.name}
                                    </td>
                                    <td className="p-3">{office.ceiling_cents === null ? 'Not assigned' : money(office.ceiling_cents)}</td>
                                    <td className="p-3">{money(office.allocated_cents)}</td>
                                    <td className="p-3">{office.remaining_cents === null ? '—' : money(office.remaining_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
