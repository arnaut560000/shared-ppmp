import type { ReactNode } from 'react';
import { type SavedItem, money } from './schedule';

type Row = Pick<SavedItem,
    'catalogue_item_id' | 'code' | 'name' | 'unit' | 'expense_class' |
    'price_cents' | 'monthly_quantities' | 'quarterly_quantities'
> & { actions?: ReactNode };

const classes = [
    { code: 'MOOE', label: 'Maintenance and Other Operating Expenses (MOOE)' },
    { code: 'CO', label: 'Capital Outlay (CO)' },
] as const;


export default function QuarterlyBreakdown({ rows }: { rows: Row[] }) {
    if (!rows.length) {
        return <p className="p-4 text-sm">No items in this list yet.</p>;
    }

    const groups = classes.map(({ code, label }) => {

        const items = rows.filter(row => row.expense_class === code).map(row => {
            return { row };
        });

        return { code, label, items };
    });

    const numberCell = 'p-2 text-right whitespace-nowrap tabular-nums';

    return (
        <div className="min-w-0 space-y-5 p-3 text-sm">
            {groups.map(({ code, label, items }) => (
                <section key={code} className="overflow-x-auto rounded-lg border">
                    <h3 className="bg-slate-50 p-3 font-semibold">{label}</h3>

                        <table className="w-full text-left" aria-label={label}>
                            <thead>
                                <tr>
                                    <th className="p-2">Item</th>
                                    {['Unit cost'].map(h => (
                                        <th key={h} className={numberCell}>{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map(({ row }) => (
                                    <tr key={row.catalogue_item_id} className="border-t align-top">
                                        <th scope="row" className="min-w-48 p-2 font-normal">
                                            <strong>{row.code}</strong> · {row.name}
                                            {row.actions}

                                        </th>

                                        <td className={numberCell}>
                                            {money(row.price_cents)} / {row.unit}
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