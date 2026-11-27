import { Errors, field, Pager, type Page, panel, Success, workTitle, YearFilter } from '@/components/procurement/workflow-controls';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';

type Status = 'awaiting_treasurer' | 'returned' | 'awaiting_number' | 'numbered';
type RequestRow = {
    id: number; version: number; purpose: string; status: Status; pr_number: string | null;
    review_note: string | null; office: string;
};
type Item = {
    app_source_line_id: number; code: string; name: string; unit: string; quantity: number;
    quarter: number; ppmp_reference: string; sourceUrl: string | null;
};
type Available = { id: number; code: string; name: string; unit: string; quarter: number; ppmp_reference: string; remaining: number };
type Event = { id: number; action: string; actor: string | null; comment: string | null; created_at: string };
type Props = {
    year: number; approvedApp: boolean; requests: Page<RequestRow>; selected: RequestRow | null; items: Item[];
    available: Available[]; editing: boolean; canRequest: boolean; canReview: boolean; canNumber: boolean;
    history: Event[]; message: string | null;
};

const url = '/procurement/requests';
const labels: Record<Status, string> = {
    awaiting_treasurer: 'Awaiting Treasurer', returned: 'Returned to department',
    awaiting_number: 'Approved · Awaiting GSO number', numbered: 'Numbered · Available to BAC',
};
const eventLabels: Record<string, string> = {
    submitted: 'Submitted', resubmitted: 'Resubmitted', awaiting_number: 'Approved by Treasurer',
    returned: 'Returned by Treasurer', numbered: 'Number assigned by GSO',
};
const emptyLine = { app_source_line_id: '', quantity: '' };

function RequestForm({ selected, items, available, year }: Pick<Props, 'selected' | 'items' | 'available' | 'year'>) {
    const form = useForm({
        year, version: selected?.version ?? null, purpose: selected?.purpose ?? '',
        items: items.length ? items.map((item) => ({
            app_source_line_id: String(item.app_source_line_id), quantity: String(item.quantity),
        })) : [{ ...emptyLine }],
    });

    function setLine(index: number, values: Partial<typeof emptyLine>) {
        form.setData('items', form.data.items.map((line, i) => i === index ? { ...line, ...values } : line));
    }

    return (
        <form className={panel} onSubmit={(event) => {
            event.preventDefault();
            if (selected) form.patch(url + '/' + selected.id);
            else form.post(url);
        }}>
            <h2 className="font-semibold">{selected ? 'Revise and resubmit PR' : 'New purchase request'}</h2>
            <label className="block text-sm font-medium">
                Purpose
                <textarea rows={3} className={field} value={form.data.purpose} maxLength={2000} required
                    onChange={(event) => form.setData('purpose', event.target.value)} />
            </label>
            {form.data.items.map((line, index) => {
                const current = available.find((item) => String(item.id) === line.app_source_line_id);
                const used = form.data.items.filter((_, i) => i !== index).map((item) => item.app_source_line_id);

                return (
                    <div key={index} className="grid items-end gap-2 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_7rem_auto]">
                        <label className="text-sm font-medium">
                            Approved APP item
                            <select className={field} value={line.app_source_line_id} required
                                onChange={(event) => setLine(index, { app_source_line_id: event.target.value, quantity: '' })}>
                                <option value="">Choose an item and planned quarter</option>
                                {available.filter((item) => item.remaining > 0 || item.id === current?.id).map((item) => (
                                    <option key={item.id} value={item.id} disabled={used.includes(String(item.id))}>
                                        {item.code} · {item.name} · Q{item.quarter} · {item.ppmp_reference} · {item.remaining} {item.unit} available
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            Quantity
                            <input className={field} type="number" min={1} max={current?.remaining} required
                                value={line.quantity} onChange={(event) => setLine(index, { quantity: event.target.value })} />
                        </label>
                        <Button type="button" variant="outline" disabled={form.data.items.length === 1}
                            onClick={() => form.setData('items', form.data.items.filter((_, i) => i !== index))}>
                            Remove
                        </Button>
                    </div>
                );
            })}
            <Errors errors={form.errors} />
            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline"
                    disabled={form.data.items.length >= Math.min(100, available.filter((item) => item.remaining > 0).length)}
                    onClick={() => form.setData('items', [...form.data.items, { ...emptyLine }])}>Add item</Button>
                <Button type="submit" disabled={form.processing || available.every((item) => item.remaining <= 0)}>
                    {form.processing ? 'Sending…' : 'Send to Treasurer'}
                </Button>
                <Button asChild variant="ghost"><Link href={url + '?year=' + year}>Cancel</Link></Button>
            </div>
            {available.every((item) => item.remaining <= 0) && <p className="text-sm text-muted-foreground">Your office has no APP quantity left to request.</p>}
        </form>
    );
}

function Decisions({ selected, canReview, canNumber }: { selected: RequestRow } & Pick<Props, 'canReview' | 'canNumber'>) {
    const form = useForm({
        action: 'approve' as 'approve' | 'return' | 'number', version: selected.version, comment: '', pr_number: '',
    });

    function decide(action: typeof form.data.action) {
        form.transform((data) => ({ ...data, action }));
        form.post(url + '/' + selected.id + '/decision');
    }

    return (
        <div className="space-y-3">
            {canReview && (
                <>
                    <label className="block text-sm font-medium">
                        Remarks (required when returning)
                        <textarea rows={3} className={field} maxLength={2000} value={form.data.comment}
                            onChange={(event) => form.setData('comment', event.target.value)} />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Button disabled={form.processing} onClick={() => decide('approve')}>Approve and send to GSO</Button>
                        <Button variant="outline" disabled={form.processing || !form.data.comment.trim()}
                            onClick={() => decide('return')}>Return to department</Button>
                    </div>
                </>
            )}
            {canNumber && (
                <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
                    event.preventDefault();
                    decide('number');
                }}>
                    <label className="text-sm font-medium">
                        Official PR number
                        <input className={field} maxLength={80} required value={form.data.pr_number}
                            onChange={(event) => form.setData('pr_number', event.target.value)} />
                    </label>
                    <Button type="submit" disabled={form.processing}>Save number and send to BAC</Button>
                </form>
            )}
            <Errors errors={form.errors} />
        </div>
    );
}

export default function PurchaseRequests(props: Props) {
    const { year, approvedApp, requests, selected, items, available, editing, canRequest, canReview, canNumber, history, message } = props;
    const formKey = [year, selected?.id ?? 'new', selected?.version ?? 0].join('-');

    return (
        <AppLayout breadcrumbs={[{ title: 'Purchase Requests', href: url }]}>
            <Head title="Purchase Requests" />
            <main className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className={workTitle}>Purchase Requests</h1>
                        <p className="mt-1 text-sm text-muted-foreground">FY {year}</p>
                    </div>
                    <YearFilter key={year} year={year} path={url} />
                </header>
                <div className="flex flex-wrap gap-3">
                    <Button asChild variant="outline">
                        <Link href={'/procurement/app?year=' + year}>View approved APP</Link>
                    </Button>
                    {canRequest && <Button asChild><Link href={url + '?year=' + year + '&create=1'}>New PR</Link></Button>}
                </div>
                <Success message={message} />
                {!approvedApp && <p className={panel}>Purchase requests open once the APP for this year is approved.</p>}
                {selected && (
                    <section className={panel}>
                        <header>
                            <h2 className="font-semibold">{selected.pr_number ?? 'PR #' + selected.id} · {selected.office}</h2>
                            <p className="text-sm text-muted-foreground">{labels[selected.status]}</p>
                        </header>
                        <p className="whitespace-pre-wrap text-sm">{selected.purpose}</p>
                        {selected.review_note && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950">{selected.review_note}</p>}
                        <ul className="divide-y border-y text-sm">
                            {items.map((item) => (
                                <li key={item.app_source_line_id} className="flex flex-wrap justify-between gap-2 py-2">
                                    <span>{item.code} · {item.name} · {item.quantity} {item.unit} · Q{item.quarter} · {item.ppmp_reference}</span>
                                    {item.sourceUrl && <Link href={item.sourceUrl} className="text-blue-700 underline">Open PPMP item</Link>}
                                </li>
                            ))}
                        </ul>
                        {(canReview || canNumber) && <Decisions key={formKey} selected={selected} canReview={canReview} canNumber={canNumber} />}
                        {history.length > 0 && <details className="text-sm">
                            <summary className="cursor-pointer font-semibold">Request history</summary>
                            <ol className="mt-2 divide-y">
                                {history.map((event) => <li key={event.id} className="py-2">
                                    {eventLabels[event.action] ?? event.action} · {event.actor ?? 'Former user'} · {event.created_at}
                                    {event.comment && <p className="mt-1 whitespace-pre-wrap">{event.comment}</p>}
                                </li>)}
                            </ol>
                        </details>}
                    </section>
                )}
                {editing && <RequestForm key={formKey} selected={selected} items={items} available={available} year={year} />}
                <section className={panel}>
                    <h2 className="font-semibold">Request list</h2>
                    {requests.data.length === 0 ? <p className="text-sm text-muted-foreground">No requests available for this fiscal year.</p> : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted">
                                    <tr>{['Request', 'Office', 'Status'].map((label) => <th key={label} scope="col" className="p-2">{label}</th>)}</tr>
                                </thead>
                                <tbody>
                                    {requests.data.map((request) => (
                                        <tr key={request.id} className="border-t">
                                            <th scope="row" className="p-2">
                                                <Link href={url + '?year=' + year + '&pr=' + request.id} className="text-blue-700 underline">
                                                    {request.pr_number ?? 'PR #' + request.id}
                                                </Link>
                                            </th>
                                            <td className="p-2">{request.office}</td>
                                            <td className="p-2">{labels[request.status]}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pager page={requests} />
                </section>
            </main>
        </AppLayout>
    );
}
