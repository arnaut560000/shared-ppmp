import { type Item, type Line, money, months, quarters, sum } from './schedule';

type Props = { item: Item; line: Line; editable: boolean; onChange: (values: number[]) => void; onRemove?: () => void };

export default function MonthlyItem({ item, line, editable, onChange, onRemove }: Props) {
    const values = line.monthly_quantities;
    return (
        <section className="space-y-4 rounded-xl border p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="font-semibold">
                        {item.code} · {item.name}
                    </h3>
                    {item.specification && <p className="text-sm">{item.specification}</p>}
                    <p className="text-sm">
                        {item.expense_class} · {money(item.price_cents)} per {item.unit}
                    </p>
                </div>
                {editable && onRemove && (
                    <button type="button" className="text-red-700 underline" onClick={onRemove}>
                        Remove
                    </button>
                )}
            </div>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                {months.map((month, i) => (
                    <label key={month} className="text-sm">
                        {month}
                        <input
                            className="bg-background mt-1 w-full rounded border p-2"
                            aria-label={month + ' quantity for ' + item.name}
                            type="number"
                            min="0"
                            max="1000000"
                            step="1"
                            disabled={!editable}
                            value={values[i] || ''}
                            placeholder="0"
                            onChange={(e) => {
                                const n = Number(e.target.value);
                                if (Number.isInteger(n) && n >= 0 && n <= 1000000) onChange(values.map((v, j) => (i === j ? n : v)));
                            }}
                        />
                    </label>
                ))}
            </div>
            <div className="grid gap-2 text-sm sm:grid-cols-4">
                {quarters(values).map((n, i) => (
                    <p key={i}>
                        Q{i + 1} · {n} {item.unit} · {money(n * item.price_cents)}
                    </p>
                ))}
            </div>
            <p className="font-semibold">
                Annual · {sum(values)} {item.unit} · {money(sum(values) * item.price_cents)}
            </p>
        </section>
    );
}
