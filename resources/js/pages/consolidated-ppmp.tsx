import { money } from '@/components/procurement/schedule';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

type Source = {
    sheet_id: number;
    office_id: number;
    reference: string;
    ppa: string | null;
    activity: string | null;
    price_cents: number;
    quantities: number[];
    amounts: number[];
};

type Item = {
    key: string;
    expense_class: string;
    code: string;
    name: string;
    specification: string | null;
    unit: string;
    quantities: number[];
    amounts: number[];
    sources: Source[];
};

type Props = {
    year: number;
    officeId: number | null;
    offices: { id: number; name: string }[];
    items: Item[];
    classes: Record<'MOOE' | 'CO', number[]>;
    totalCents: number;
    planCount: number;
};

const url = '/consolidated-ppmp';
const quarters = ['Q1', 'Q2', 'Q3', 'Q4'];
const sum = (values: number[]) => values.reduce((total, value) => total + value, 0);
const cell = 'px-3 py-2 align-top';
const numeric = `${cell} whitespace-nowrap text-right tabular-nums`;

export default function ConsolidatedPpmp({
    year, officeId, offices, items, classes, totalCents, planCount,
}: Props) {
    const form = useForm({ year: String(year), office_id: String(officeId ?? '') });
    const officeNames = new Map(offices.map((office) => [office.id, office.name]));
    const query = new URLSearchParams({ year: String(year) });
    if (officeId !== null) query.set('office_id', String(officeId));
    const scope = officeId === null ? 'All departments' : officeNames.get(officeId);
    const grand = quarters.map((_, q) => classes.MOOE[q] + classes.CO[q]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Consolidated PPMP', href: url }]}>
            <Head title="Consolidated PPMP" />
            <div className="min-w-0 flex-1 space-y-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-blue-950">Consolidated PPMP</h1>
                        <p className="mt-1 text-sm text-slate-600">
                            FY {year} · {scope} · {planCount} approved {planCount === 1 ? 'sheet' : 'sheets'}
                        </p>
                    </div>
                    {items.length > 0 && (
                        <a href={`${url}/excel?${query}`} className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white">
                            Download Excel
                        </a>
                    )}
                </header>

                <form onSubmit={(event) => {
                    event.preventDefault();
                    form.get(url, { preserveState: false });
                }} className="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
                    <label className="text-sm font-medium">
                        Fiscal year
                        <input name="year" type="number" min={2020} max={2100} required
                            value={form.data.year} onChange={(event) => form.setData('year', event.target.value)}
                            className="mt-1 block w-28 rounded-lg border px-3 py-2" />
                        {form.errors.year && <span role="alert" className="text-red-700">{form.errors.year}</span>}
                    </label>
                    <label className="text-sm font-medium">
                        Office
                        <select name="office_id" value={form.data.office_id}
                            onChange={(event) => form.setData('office_id', event.target.value)}
                            className="mt-1 block max-w-full rounded-lg border bg-white px-3 py-2">
                            <option value="">All departments</option>
                            {offices.map((office) => (
                                <option key={office.id} value={office.id}>{office.name}</option>
                            ))}
                        </select>
                        {form.errors.office_id && <span role="alert" className="text-red-700">{form.errors.office_id}</span>}
                    </label>
                    <button disabled={form.processing} className="rounded-lg border border-blue-700 px-4 py-2 text-sm font-semibold text-blue-700 disabled:opacity-50">
                        Apply filters
                    </button>
                </form>

                {items.length === 0 ? (
                    <p className="rounded-xl border bg-white p-5">
                        No approved PPMP items for {scope} in FY {year}.
                    </p>
                ) : (
                    <>
                        <section className="overflow-x-auto rounded-xl border bg-white">
                            <table className="w-full text-left text-sm">
                                <caption className="p-3 text-left font-bold text-blue-950">Budget summary</caption>
                                <thead className="bg-blue-50">
                                    <tr>
                                        <th scope="col" className={cell}>Expense class</th>
                                        {[...quarters, 'Annual'].map((q) => (
                                            <th key={q} scope="col" className={numeric}>{q}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {(['MOOE', 'CO'] as const).map((name) => (
                                        <tr key={name} className="border-t">
                                            <th scope="row" className={cell}>{name}</th>
                                            {classes[name].map((amount, q) => (
                                                <td key={q} className={numeric}>{money(amount)}</td>
                                            ))}
                                            <td className={numeric}>{money(sum(classes[name]))}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot className="border-t bg-slate-50 font-bold">
                                    <tr>
                                        <th scope="row" className={cell}>Grand total</th>
                                        {grand.map((amount, q) => (
                                            <td key={q} className={numeric}>{money(amount)}</td>
                                        ))}
                                        <td className={numeric}>{money(totalCents)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </section>

                        <section className="overflow-x-auto rounded-xl border bg-white">
                            <table className="w-full text-left text-sm">
                                <caption className="p-3 text-left font-bold text-blue-950">Approved items</caption>
                                <thead className="bg-blue-50">
                                    <tr>
                                        <th scope="col" className={cell}>Item and source sheets</th>
                                        <th scope="col" className={cell}>Class</th>
                                        {[...quarters, 'Annual'].map((q) => (
                                            <th key={q} scope="col" className={numeric}>{q} qty / cost</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {items.map((item) => (
                                        <tr key={item.key}>
                                            <td className={`${cell} min-w-64`}>
                                                <details>
                                                    <summary className="cursor-pointer font-semibold text-blue-800">
                                                        {item.code} · {item.name} ({item.unit})
                                                    </summary>
                                                    {item.specification && (
                                                        <p className="mt-2 text-slate-600">{item.specification}</p>
                                                    )}
                                                    <ul className="mt-2 space-y-2">
                                                        {item.sources.map((source) => (
                                                            <li key={source.sheet_id} className="border-l-2 border-blue-200 pl-2">
                                                                <strong>{officeNames.get(source.office_id)}</strong>
                                                                <div>{[source.ppa, source.activity, source.reference].filter(Boolean).join(' → ')}</div>
                                                                <div>
                                                                    {sum(source.quantities)} {item.unit} × {money(source.price_cents)}
                                                                    {' = '}{money(sum(source.amounts))}
                                                                </div>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </details>
                                            </td>
                                            <td className={cell}>{item.expense_class}</td>
                                            {item.quantities.map((qty, q) => (
                                                <td key={q} className={numeric}>
                                                    <div>{qty}</div>
                                                    <div className="text-slate-600">{money(item.amounts[q])}</div>
                                                </td>
                                            ))}
                                            <td className={`${numeric} font-semibold`}>
                                                <div>{sum(item.quantities)}</div>
                                                <div>{money(sum(item.amounts))}</div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

