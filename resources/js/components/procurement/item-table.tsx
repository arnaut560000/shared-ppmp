import QuarterlyBreakdown from './quarterly-breakdown';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useRef, useState } from 'react';
import MonthlyItem from './monthly-item';
import { type Expense, type Item, type Line, money, sum } from './schedule';

type Props = {
    lines: Line[];
    items: Map<number, Item>;
    catalogue: Item[];
    editable: boolean;
    onChange: (lines: Line[]) => void;
};
type Selection = { item: Item; months: number[]; adding: boolean };
const primary = 'rounded-md bg-blue-700 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50';
const secondary = 'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50';

export default function ItemTable({ lines, items, catalogue, editable, onChange }: Props) {
    const [open, setOpen] = useState(false);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');
    const [expenseClass, setExpenseClass] = useState<Expense | ''>('');
    const [page, setPage] = useState(1);
    const opener = useRef<HTMLButtonElement | null>(null);
    const categories = [...new Set(catalogue.map((item) => item.category).filter((value): value is string => Boolean(value)))].sort();
    function categoryHasItems(
        categoryName: string,
        classification: Expense | '' = expenseClass,
    ): boolean {
        return catalogue.some(
            (item) =>
                item.category === categoryName &&
                (!classification || item.expense_class === classification),
        );
    }
    const query = search.trim().toLowerCase();
    const matches = catalogue.filter(
        (item) =>
            (!expenseClass || item.expense_class === expenseClass) &&
            (!category || item.category === category) &&
            [item.name, item.code, item.specification].some((value) => value?.toLowerCase().includes(query)),
    );
    const pages = Math.max(1, Math.ceil(matches.length / 8));
    const currentPage = Math.min(page, pages);
    const pageItems = matches.slice((currentPage - 1) * 8, currentPage * 8);
    function openCatalogue(trigger: HTMLButtonElement) {
        opener.current = trigger;
        setSearch('');
        setCategory('');
        setExpenseClass('');
        setPage(1);
        setSelection(null);
        setOpen(true);
    }
    function inspect(line: Line, trigger: HTMLButtonElement) {
        const item = items.get(line.catalogue_item_id);
        if (!item) return;
        opener.current = trigger;
        setSelection({ item, months: [...line.monthly_quantities], adding: false });
        setOpen(true);
    }
    function apply() {
        if (!selection || !editable || !sum(selection.months)) return;
        const next: Line = {
            catalogue_item_id: selection.item.id,
            expected_price_cents: selection.item.price_cents,
            monthly_quantities: selection.months,
        };
        onChange(selection.adding ? [...lines, next] : lines.map((line) => (line.catalogue_item_id === next.catalogue_item_id ? next : line)));
        setOpen(false);
    }
    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                <h3 className="font-semibold">PPMP items ({lines.length})</h3>
                {editable && (
                    <button type="button" className={primary} onClick={(event) => openCatalogue(event.currentTarget)}>
                        + Add item from catalog
                    </button>
                )}
            </header>
            {lines.filter(line => !items.has(line.catalogue_item_id)).map(line => (
                <div key={line.catalogue_item_id} role="alert" className="p-3 text-sm text-amber-900">
                    Item #{line.catalogue_item_id} is unavailable. Reload the sheet or remove the item to see quarterly totals.
                    {editable && (
                        <button type="button" className="ml-2 underline"
                            onClick={() => onChange(lines.filter(other => other !== line))}>
                            Remove unavailable item
                        </button>
                    )}
                </div>
            ))}
            {lines.every(line => items.has(line.catalogue_item_id)) && <QuarterlyBreakdown rows={lines.map(line => {
                const item = items.get(line.catalogue_item_id)!;

                return {
                    ...item,
                    ...line,
                    price_cents: line.expected_price_cents,
                    quarterly_quantities: null,
                    actions: (
                        <div className="mt-1 flex gap-3">
                            <button
                                type="button"
                                className="text-blue-700 underline"
                                aria-label={(editable ? 'Edit months for ' : 'View months for ') + item.code + ' ' + item.name}
                                onClick={e => inspect(line, e.currentTarget)}
                            >
                                {editable ? 'Edit months' : 'View months'}
                            </button>

                            {editable && (
                                <button
                                    type="button"
                                    className="text-red-700 underline"
                                    aria-label={'Remove ' + item.name}
                                    onClick={() => onChange(
                                        lines.filter(other => other.catalogue_item_id !== item.id)
                                    )}
                                >
                                    Remove
                                </button>
                            )}
                        </div>
                    ),
                };
            })} />}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    className="max-h-[90dvh] w-[calc(100%-2rem)] max-w-4xl overflow-y-auto bg-white text-slate-900"
                    aria-describedby="ppmp-catalog-context"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        opener.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {selection ? (selection.adding ? 'Add PPMP item from catalog' : 'Monthly item schedule') : 'Item catalog'}
                        </DialogTitle>
                        <DialogDescription id="ppmp-catalog-context">
                            {selection
                                ? `${selection.item.expense_class} item. Quantities are scheduled by month.`
                                : 'Filter by MOOE or CO, search the catalogue, and select an item for this procurement list.'}
                        </DialogDescription>
                    </DialogHeader>
                    {selection ? (
                        <>
                            <MonthlyItem
                                item={selection.item}
                                line={{
                                    catalogue_item_id: selection.item.id,
                                    expected_price_cents: selection.item.price_cents,
                                    monthly_quantities: selection.months,
                                }}
                                editable={editable}
                                onChange={(months) => setSelection({ ...selection, months })}
                            />
                            <DialogFooter>
                                {selection.adding && (
                                    <button type="button" className={secondary} onClick={() => setSelection(null)}>
                                        Back to catalog
                                    </button>
                                )}
                                <button type="button" className={secondary} onClick={() => setOpen(false)}>
                                    {editable ? 'Cancel' : 'Close'}
                                </button>
                                {editable && (
                                    <button type="button" disabled={!sum(selection.months)} className={primary} onClick={apply}>
                                        {selection.adding ? 'Add to PPMP' : 'Apply changes'}
                                    </button>
                                )}
                            </DialogFooter>
                        </>
                    ) : (
                        <>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <label className="min-w-0 text-sm sm:col-span-2">
                                    Search items
                                    <input
                                        type="search"
                                        className="mt-1 block w-full rounded border p-2"
                                        placeholder="Item code, name or specification"
                                        value={search}
                                        onChange={(e) => {
                                            setSearch(e.target.value);
                                            setPage(1);
                                        }}
                                    />
                                </label>
                                <label className="min-w-0 text-sm">
                                    Expense classification
                                    <select
                                        className="mt-1 block w-full min-w-0 rounded border p-2"
                                        value={expenseClass}
                                        onChange={(e) => {
                                            const nextClass = e.target.value as Expense | '';
                                            setExpenseClass(nextClass);
                                            if (category && !categoryHasItems(category, nextClass)) {
                                                setCategory('');
                                            }
                                            setPage(1);
                                        }}
                                    >
                                        <option value="">All items</option>
                                        <option value="MOOE">MOOE — Maintenance and Other Operating Expenses</option>
                                        <option value="CO">CO — Capital Outlay</option>
                                    </select>
                                </label>
                                <label className="min-w-0 text-sm">
                                    Category
                                    <select
                                        className="mt-1 block w-full min-w-0 rounded border p-2"
                                        value={category}
                                        aria-describedby="ppmp-category-help"
                                        onChange={(e) => {
                                            setCategory(e.target.value);
                                            setPage(1);
                                        }}
                                    >
                                        <option value="">All categories</option>
                                        {categories.map((c) => {
                                            const available = categoryHasItems(c);

                                            return (
                                                <option
                                                    key={c}
                                                    value={c}
                                                    disabled={!available}
                                                    className={available ? '' : 'text-slate-400'}
                                                >
                                                    {c}
                                                    {!available ? ` — No ${expenseClass || 'available'} items` : ''}
                                                </option>
                                            );
                                        })}
                                    </select>
                                </label>
                            </div>
                            <p id="ppmp-category-help" className="text-xs text-slate-600">
                                Categories without items in the selected expense classification are disabled.
                            </p>
                            {(search || category || expenseClass) && (
                                <button
                                    type="button"
                                    className={secondary + ' justify-self-start'}
                                    onClick={() => {
                                        setSearch('');
                                        setCategory('');
                                        setExpenseClass('');
                                        setPage(1);
                                    }}
                                >
                                    Clear filters
                                </button>
                            )}
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-slate-100">
                                        <tr>
                                            {['Code', 'Item description', 'Class', 'Unit', 'Unit cost', ''].map((h, i) => (
                                                <th key={i} className="p-2">
                                                    {h}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pageItems.map((item) => {
                                            const added = lines.some((line) => line.catalogue_item_id === item.id);
                                            return (
                                                <tr key={item.id} className="border-t">
                                                    <td className="p-2">{item.code}</td>
                                                    <td className="p-2">
                                                        {item.name}
                                                        <p className="text-xs text-slate-600">{item.specification}</p>
                                                    </td>
                                                    <td className="p-2">
                                                        <span
                                                            title={item.expense_class === 'MOOE' ? 'Maintenance and Other Operating Expenses' : 'Capital Outlay'}
                                                            className={'inline-flex rounded-full px-2 py-1 text-xs font-semibold ' + (
                                                                item.expense_class === 'MOOE'
                                                                    ? 'bg-blue-100 text-blue-900'
                                                                    : 'bg-amber-100 text-amber-900'
                                                            )}
                                                        >
                                                            {item.expense_class}
                                                        </span>
                                                    </td>
                                                    <td className="p-2">{item.unit}</td>
                                                    <td className="p-2">{money(items.get(item.id)?.price_cents ?? item.price_cents)}</td>
                                                    <td className="p-2">
                                                        <button
                                                            type="button"
                                                            className={primary}
                                                            disabled={added}
                                                            aria-label={(added ? 'Already added ' : 'Select ') + item.name}
                                                            onClick={() =>
                                                                setSelection({
                                                                    item: items.get(item.id) ?? item,
                                                                    months: Array<number>(12).fill(0),
                                                                    adding: true,
                                                                })
                                                            }
                                                        >
                                                            {added ? 'Added' : 'Select'}
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                                {!matches.length && (
                                    <p className="p-4 text-sm">
                                        {catalogue.length
                                            ? `No ${expenseClass || 'catalogue'} items match your search and filters. Try another search or clear the filters.`
                                            : 'No active MOOE or CO catalogue items are available.'}
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                                <span role="status" aria-live="polite" aria-atomic="true">
                                    {matches.length} {expenseClass ? expenseClass + ' ' : ''}items · Page {currentPage} of {pages}
                                </span>
                                <div className="flex gap-2">
                                    <button type="button" className={secondary} disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)}>
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        className={secondary}
                                        disabled={currentPage === pages}
                                        onClick={() => setPage(currentPage + 1)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
