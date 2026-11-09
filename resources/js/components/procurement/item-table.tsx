import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useRef, useState } from 'react';
import MonthlyItem from './monthly-item';
import { type Item, type Line, money, sum } from './schedule';

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
    const [page, setPage] = useState(1);
    const opener = useRef<HTMLButtonElement | null>(null);
    const categories = [...new Set(catalogue.map((item) => item.category).filter((value): value is string => Boolean(value)))].sort();
    const query = search.trim().toLowerCase();
    const matches = catalogue.filter(
        (item) =>
            (!category || item.category === category) &&
            [item.name, item.code, item.specification].some((value) => value?.toLowerCase().includes(query)),
    );
    const pages = Math.max(1, Math.ceil(matches.length / 8));
    const currentPage = Math.min(page, pages);
    const pageItems = matches.slice((currentPage - 1) * 8, currentPage * 8);
    const total = lines.reduce((amount, line) => amount + sum(line.monthly_quantities) * line.expected_price_cents, 0);
    function openCatalogue(trigger: HTMLButtonElement) {
        opener.current = trigger;
        setSearch('');
        setCategory('');
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
            {lines.length === 0 ? (
                <p className="p-4 text-sm text-slate-600">No items in this list yet.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr>
                                {['Code', 'Item description', 'Unit', 'Quantity', 'Unit cost', 'Total cost', 'Actions'].map((h) => (
                                    <th key={h} className="p-3">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => {
                                const item = items.get(line.catalogue_item_id);
                                if (!item) return null;
                                const qty = sum(line.monthly_quantities);
                                return (
                                    <tr key={item.id} className="border-t">
                                        <td className="p-3">{item.code}</td>
                                        <td className="p-3">{item.name}</td>
                                        <td className="p-3">{item.unit}</td>
                                        <td className="p-3">{qty}</td>
                                        <td className="p-3 whitespace-nowrap">{money(item.price_cents)}</td>
                                        <td className="p-3 whitespace-nowrap">{money(qty * item.price_cents)}</td>
                                        <td className="p-3">
                                            <div className="flex gap-3">
                                                <button
                                                    type="button"
                                                    className="text-blue-700 underline"
                                                    onClick={(e) => inspect(line, e.currentTarget)}
                                                >
                                                    {editable ? 'Edit' : 'View'} schedule
                                                </button>
                                                {editable && (
                                                    <button
                                                        type="button"
                                                        className="text-red-700 underline"
                                                        aria-label={'Remove ' + item.name}
                                                        onClick={() => onChange(lines.filter((row) => row.catalogue_item_id !== item.id))}
                                                    >
                                                        Remove
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr className="border-t font-semibold">
                                <td colSpan={5} className="p-3 text-right">
                                    Total estimated cost
                                </td>
                                <td colSpan={2} className="p-3">
                                    {money(total)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
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
                            {selection ? 'Quantities are scheduled by month.' : 'Select an item for this procurement list.'}
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
                            <div className="flex flex-wrap gap-3">
                                <label className="min-w-48 flex-1 text-sm">
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
                                <label className="text-sm">
                                    Category
                                    <select
                                        className="mt-1 block rounded border p-2"
                                        value={category}
                                        onChange={(e) => {
                                            setCategory(e.target.value);
                                            setPage(1);
                                        }}
                                    >
                                        <option value="">All categories</option>
                                        {categories.map((c) => (
                                            <option key={c} value={c}>
                                                {c}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-slate-100">
                                        <tr>
                                            {['Code', 'Item description', 'Unit', 'Unit cost', ''].map((h, i) => (
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
                                        {catalogue.length ? 'No matching items.' : 'No active MOOE or CO catalogue items are available.'}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span>
                                    {matches.length} items · Page {currentPage} of {pages}
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
