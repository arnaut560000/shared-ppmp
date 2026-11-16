import type { ReactNode } from 'react';
import { type SavedItem, money, quarters, sum } from './schedule';

type Row = Pick<SavedItem,
    'catalogue_item_id' | 'code' | 'name' | 'unit' | 'expense_class' |
    'price_cents' | 'monthly_quantities' | 'quarterly_quantities'
> & { actions?: ReactNode };

const classes = [
    { code: 'MOOE', label: 'Maintenance and Other Operating Expenses (MOOE)' },
    { code: 'CO', label: 'Capital Outlay (CO)' },
] as const;

const periods = ['Q1', 'Q2', 'Q3', 'Q4'];

export default function QuarterlyBreakdown({ rows }: { rows: Row[] }) {
    if (!rows.length) {
        return <p className="p-4 text-sm">No items in this list yet.</p>;
    }

    const groups = classes.map(({ code, label }) => {
        const totals = [0, 0, 0, 0];

        const items = rows.filter(row => row.expense_class === code).map(row => {
            const quantities = row.monthly_quantities?.length === 12
                ? quarters(row.monthly_quantities)
                : row.quarterly_quantities;

            if (quantities?.length !== 4) {
                throw new Error('Invalid schedule for ' + row.code);
            }

            const costs = quantities.map(qty => qty * row.price_cents);
            costs.forEach((cost, i) => {
                totals[i] += cost;
            });

            return { row, quantities, costs };
        });

        return { code, label, totals, items };
    });

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
                            <th className="p-2">Expense class</th>
                            {[...periods, 'Annual'].map(h => (
                                <th key={h} className={numberCell}>{h}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {[
                            ...groups.map(g => ({ label: g.code, totals: g.totals })),
                        ].map(({ label, totals }) => (
                            <tr key={label} className="border-t">
                                <th scope="row" className="p-2">{label}</th>
                                {[...totals, sum(totals)].map((value, i) => (
                                    <td key={i} className={numberCell}>{money(value)}</td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {groups.map(({ code, label, items }) => (
                <section key={code} className="overflow-x-auto rounded-lg border">
                    <h3 className="bg-slate-50 p-3 font-semibold">{label}</h3>

                        <table className="w-full text-left" aria-label={label}>
                            <thead>
                                <tr>
                                    <th className="p-2">Item</th>
                                    {['Unit cost', ...periods, 'Annual'].map(h => (
                                        <th key={h} className={numberCell}>{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map(({ row, quantities, costs }) => (
                                    <tr key={row.catalogue_item_id} className="border-t align-top">
                                        <th scope="row" className="min-w-48 p-2 font-normal">
                                            <strong>{row.code}</strong> · {row.name}
                                            {row.actions}

                                        </th>

                                        <td className={numberCell}>
                                            {money(row.price_cents)} / {row.unit}
                                        </td>

                                        {quantities.map((qty, i) => (
                                            <td key={i} className={numberCell}>
                                                {money(costs[i])}
                                                <div className="text-xs text-slate-600">
                                                    {qty} {row.unit}
                                                </div>
                                            </td>
                                        ))}

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
                </section>
            ))}
        </div>
    );
}