import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { type ReceivingByItem, type SavedItem, money, months, quarters, sum } from './schedule';

type Row = Pick<SavedItem,
    'catalogue_item_id' | 'code' | 'name' | 'unit' | 'expense_class' |
    'price_cents' | 'monthly_quantities' | 'quarterly_quantities'
> & { actions?: ReactNode };

const classes = [
    { code: 'MOOE', label: 'Maintenance and Other Operating Expenses (MOOE)' },
    { code: 'CO', label: 'Capital Outlay (CO)' },
] as const;

const periods = ['Q1 · Jan–Mar', 'Q2 · Apr–Jun', 'Q3 · Jul–Sep', 'Q4 · Oct–Dec'];

export default function QuarterlyBreakdown({ rows, receiving }: { rows: Row[]; receiving?: ReceivingByItem | null }) {
    if (!rows.length) {
        return <p className="p-4 text-sm">No items in this list yet.</p>;
    }

    const incomplete = rows.filter(row =>
        !classes.some(group => group.code === row.expense_class) ||
        (row.monthly_quantities?.length !== 12 && row.quarterly_quantities?.length !== 4)
    );
    if (incomplete.length) {
        return (
            <p role="alert" className="p-4 text-sm text-amber-900">
                Quarterly totals are unavailable. Check the expense class and schedule for {incomplete.map(row => row.code).join(', ')}.
            </p>
        );
    }

    const groups = classes.map(({ code, label }) => {
        const totals = [0, 0, 0, 0];

        const items = rows.filter(row => row.expense_class === code).map(row => {
            const quantities = row.monthly_quantities?.length === 12
                ? quarters(row.monthly_quantities)
                : (row.quarterly_quantities ?? []);

            const costs = quantities.map(qty => qty * row.price_cents);
            costs.forEach((cost, i) => {
                totals[i] += cost;
            });

            return { row, quantities, costs };
        });

        return { code, label, totals, items };
    });

    const grand = periods.map((_, i) => sum(groups.map(group => group.totals[i])));
    const numberCell = 'p-2 text-right whitespace-nowrap tabular-nums';

    return (
        <div className="min-w-0 space-y-5 p-3 text-sm">
            <div className="overflow-x-auto">
                <table className="w-full text-left">
                    <caption className="pb-2 text-left font-semibold">
                        Quarterly budget summary
                    </caption>
                    <thead className="bg-blue-50 text-blue-950">
                        <tr>
                            <th scope="col" className="p-2">Expense class</th>
                            {[...periods, 'Annual'].map(h => (
                                <th scope="col" key={h} className={numberCell}>{h}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {[
                            ...groups.map(g => ({ label: g.code, totals: g.totals })),
                            { label: 'Total PPMP', totals: grand },
                        ].map(({ label, totals }) => (
                            <tr key={label} className="border-t last:bg-blue-50 last:font-bold">
                                <th scope="row" className="p-2">{label}</th>
                                {[...totals, sum(totals)].map((value, i) => (
                                    <td key={i} className={numberCell}>{money(value)}</td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {receiving && <p className="text-xs text-slate-600">
                Delivery progress is grouped by the original planned quarter. Open orders and receipts to see actual delivery dates.
            </p>}

            {groups.map(({ code, label, items }) => (
                <section key={code} className="overflow-x-auto rounded-lg border">
                    <h3 className="bg-slate-50 p-3 font-semibold">{label}</h3>

                    {!items.length ? (
                        <p className="p-3">No {code} items on this sheet.</p>
                    ) : (
                        <table className="w-full text-left" aria-label={label}>
                            <thead>
                                <tr>
                                    <th scope="col" className="p-2">Item</th>
                                    {['Unit cost', ...periods, 'Annual'].map(h => (
                                        <th scope="col" key={h} className={numberCell}>{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map(({ row, quantities, costs }) => (
                                    <tr key={row.catalogue_item_id} className="border-t align-top">
                                        <th scope="row" className="min-w-48 p-2 font-normal">
                                            <strong>{row.code}</strong> · {row.name}
                                            {row.actions}

                                            {!row.actions && row.monthly_quantities?.length === 12 && (
                                                <details>
                                                    <summary className="cursor-pointer text-blue-700">
                                                        View months
                                                    </summary>
                                                    {row.monthly_quantities.map((qty, i) => (
                                                        <div key={i}>{months[i]} · {qty} {row.unit}</div>
                                                    ))}
                                                </details>
                                            )}
                                        </th>

                                        <td className={numberCell}>
                                            {money(row.price_cents)} / {row.unit}
                                        </td>

                                        {quantities.map((qty, i) => {
                                            const progress = receiving?.[row.catalogue_item_id]?.[i];

                                            return (
                                                <td key={i} className={numberCell}>
                                                    {money(costs[i])}
                                                    <div className="text-xs text-slate-600">
                                                        {qty} {row.unit}{progress && ' planned'}
                                                    </div>
                                                    {progress && (
                                                        <div className="mt-1 space-y-1 border-t pt-1 text-xs">
                                                            <div>{progress.received} received · {qty - progress.received} remaining</div>
                                                            <div>{progress.ordered} ordered</div>
                                                            <Link href={progress.url} className="text-blue-700 underline"
                                                                aria-label={'Orders and receipts for ' + row.name + ', planned Q' + (i + 1)}>
                                                                Orders and receipts
                                                            </Link>
                                                        </div>
                                                    )}
                                                </td>
                                            );
                                        })}

                                        <td className={numberCell}>
                                            <strong>{money(sum(costs))}</strong>
                                            <div className="text-xs text-slate-600">
                                                {sum(quantities)} {row.unit}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            ))}
        </div>
    );
}