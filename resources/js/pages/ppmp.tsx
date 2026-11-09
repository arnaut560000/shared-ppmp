import PlanningStages from '@/components/planning-stages';
import AllocationSummary from '@/components/procurement/allocation-summary';
import ItemTable from '@/components/procurement/item-table';
import { type Item, type Line, money, sum, type Workspace } from '@/components/procurement/schedule';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const button = 'rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white disabled:opacity-50';
const field = 'rounded-lg border bg-background px-3 py-2';

function Editor(p: Workspace) {
    const form = useForm({
        reference: p.reference,
        version: p.selected?.version ?? 0,
        items: (p.selected?.items ?? []).map(
            (i): Line => ({
                catalogue_item_id: i.catalogue_item_id,
                expected_price_cents: i.price_cents,
                monthly_quantities: [...(i.monthly_quantities ?? [])],
            }),
        ),
    });
    useEffect(() => {
        if (!form.isDirty || !p.canEdit) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        const stop = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !window.confirm('Leave without saving this list?')) event.preventDefault();
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            stop();
            window.removeEventListener('beforeunload', warn);
        };
    }, [form.isDirty, p.canEdit]);
    const items = new Map<number, Item>(p.catalogue.map((i) => [i.id, i]));
    for (const saved of p.selected?.items ?? []) items.set(saved.catalogue_item_id, { ...saved, id: saved.catalogue_item_id });
    const used = { MOOE: 0, CO: 0 };
    for (const line of form.data.items) {
        const item = items.get(line.catalogue_item_id);
        if (item) used[item.expense_class] += sum(line.monthly_quantities) * line.expected_price_cents;
    }
    const editable = p.canEdit && !form.processing;
    return (
        <form
            className="space-y-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:p-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (!editable) return;
                const url = '/aips/' + p.ppa.id + '/ppmp';
                if (p.selected) form.put(url + '/' + p.selected.id, { preserveScroll: true });
                else form.post(url, { preserveScroll: true });
            }}
        >
            <label className="block">
                PPMP reference
                <input
                    className={field + ' mt-1 block'}
                    value={form.data.reference}
                    maxLength={100}
                    required
                    disabled={!editable}
                    onChange={(e) => form.setData('reference', e.target.value)}
                />
            </label>
            <ItemTable
                lines={form.data.items}
                items={items}
                catalogue={p.catalogue}
                editable={editable}
                onChange={(lines) => form.setData('items', lines)}
            />
            <AllocationSummary available={p.available} used={used} />
            {Object.entries(form.errors).map(([key, error]) => (
                <p key={key} role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            ))}
            {p.canEdit && (
                <button className={button} disabled={!editable || !form.data.items.length}>
                    {form.processing ? 'Saving…' : 'Save monthly draft'}
                </button>
            )}
        </form>
    );
}

export default function Ppmp(p: Workspace) {
    const url = '/aips/' + p.ppa.id + '/ppmp';
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Annual investment plans', href: p.backUrl },
                { title: 'Monthly PPMP', href: url },
            ]}
        >
            <Head title={'PPMP — ' + p.ppa.reference} />
            <div className="aip-workspace min-w-0 flex-1 space-y-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header>
                    <h1 className="text-2xl font-bold">PPAs and PPMP</h1>
                    <p className="mt-2 font-semibold text-blue-950">
                        {p.ppa.reference} — {p.ppa.title}
                    </p>
                    <p>
                        {p.office} · FY {p.ppa.fiscal_year} · {p.ppa.fund_source} · PPA {p.ppa.status}
                    </p>
                </header>
                <PlanningStages workspace={p.backUrl} summary={p.summaryUrl} current="ppas" />
                <nav aria-label="Procurement lists" className="flex flex-wrap gap-2">
                    {p.sheets.map((s) => (
                        <Link
                            key={s.id}
                            href={url + '?sheet=' + s.id}
                            className={'rounded border p-2 ' + (s.id === p.selected?.id ? 'font-bold' : '')}
                        >
                            {s.reference} · {s.status} · {money(s.total_cents)}
                        </Link>
                    ))}
                    {p.canPrepare && p.sheets.length > 0 && (
                        <Link className="rounded border p-2 text-blue-700" href={url + '?new=1'}>
                            + New list
                        </Link>
                    )}
                </nav>
                {p.message && (
                    <p role="status" className="rounded bg-blue-50 p-3 text-blue-950">
                        {p.message}
                    </p>
                )}
                {p.legacy && p.selected ? (
                    <section className="space-y-3">
                        <h2 className="font-semibold">Saved quarterly list · Read only</h2>
                        {p.selected.items.map((i) => (
                            <div key={i.catalogue_item_id}>
                                <p>
                                    {i.code} — {i.name} · {money(i.price_cents)} / {i.unit}
                                </p>
                                <p>
                                    {(i.quarterly_quantities ?? [])
                                        .map((q, n) => 'Q' + (n + 1) + ': ' + q + ' (' + money(q * i.price_cents) + ')')
                                        .join(' · ')}
                                </p>
                            </div>
                        ))}
                    </section>
                ) : p.selected || p.canEdit ? (
                    <Editor key={[p.ppa.id, p.selected?.id ?? 'new', p.selected?.version ?? 0].join('-')} {...p} />
                ) : (
                    <p>No list is available to open for this PPA.</p>
                )}
            </div>
        </AppLayout>
    );
}
