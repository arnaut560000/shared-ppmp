import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Order = {
    id: number;
    reference: string;
    ordered_on: string;
    quantity: number;
    register_equipment: boolean;
    received: number;
    receipts: { id: number; reference: string; received_on: string; quantity: number }[];
};
type Props = {
    item: { id: number; code: string; name: string; unit: string };
    sheet: { reference: string; fiscal_year: number };
    office: string;
    quarter: number;
    planned: number;
    orders: Order[];
    ppmpUrl: string;
    canOrder: boolean;
    canReceive: boolean;
    today: string;
    message: string | null;
};

const field = 'mt-1 block w-full rounded-lg border bg-background px-3 py-2 text-sm';
const entry = 'grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-2';

function OrderForm({ endpoint, available, today }: { endpoint: string; available: number; today: string }) {
    const form = useForm({ reference: '', ordered_on: today, quantity: '', register_equipment: false as boolean });

    return (
        <form className={entry} onSubmit={(event) => {
            event.preventDefault();
            form.post(endpoint + '/orders', { preserveScroll: true, onSuccess: () => form.reset() });
        }}>
            <h2 className="font-semibold sm:col-span-2">Link an existing purchase order</h2>
            <label className="block text-sm">
                PO reference
                <input className={field} value={form.data.reference} maxLength={120} required
                    onChange={(event) => form.setData('reference', event.target.value)} />
            </label>
            <label className="block text-sm">
                PO date
                <input type="date" className={field} value={form.data.ordered_on} max={today} required
                    onChange={(event) => form.setData('ordered_on', event.target.value)} />
            </label>
            <label className="block text-sm">
                Quantity for this planned quarter
                <input type="number" className={field} value={form.data.quantity} min={1} max={available} required
                    onChange={(event) => form.setData('quantity', event.target.value)} />
            </label>
            <label className="flex items-start gap-2 self-center text-sm">
                <input type="checkbox" className="mt-1" checked={form.data.register_equipment}
                    onChange={(event) => form.setData('register_equipment', event.target.checked)} />
                Register each accepted unit as tagged equipment
            </label>
            <div className="space-y-2 sm:col-span-2">
                {Object.entries(form.errors).map(([key, message]) => <InputError key={key} message={message} />)}
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Linking…' : 'Link purchase order'}</Button>
            </div>
        </form>
    );
}

function ReceiptForm({ order, endpoint, today }: { order: Order; endpoint: string; today: string }) {
    const remaining = order.quantity - order.received;
    const form = useForm({ reference: '', received_on: today, quantity: '' });
    const [tags, setTags] = useState('');

    return (
        <form className={entry} onSubmit={(event) => {
            event.preventDefault();
            const units = order.register_equipment ? tags.split(/\r?\n/).filter((line) => line.trim()).map((line) => {
                const [tag, ...serial] = line.split(',');
                return { property_tag: tag.trim(), serial_number: serial.join(',').trim() || null };
            }) : [];
            form.transform((data) => ({ ...data, units }));
            form.post(endpoint + '/orders/' + order.id + '/receipts', {
                preserveScroll: true,
                onSuccess: () => { form.reset(); setTags(''); },
            });
        }}>
            <h3 className="font-semibold sm:col-span-2">Record accepted delivery</h3>
            <label className="block text-sm">
                Delivery or acceptance reference
                <input className={field} value={form.data.reference} maxLength={120} required
                    onChange={(event) => form.setData('reference', event.target.value)} />
            </label>
            <label className="block text-sm">
                Actual date received
                <input type="date" className={field} value={form.data.received_on} min={order.ordered_on} max={today} required
                    onChange={(event) => form.setData('received_on', event.target.value)} />
            </label>
            <label className="block text-sm">
                Quantity accepted
                <input type="number" className={field} value={form.data.quantity} min={1}
                    max={order.register_equipment ? Math.min(remaining, 250) : remaining} required
                    onChange={(event) => form.setData('quantity', event.target.value)} />
            </label>
            {order.register_equipment && (
                <label className="block text-sm sm:col-span-2">
                    Property tags and optional serial numbers
                    <textarea className={field} rows={4} value={tags} required
                        placeholder="MO-LAP-001, SN1234" onChange={(event) => setTags(event.target.value)} />
                    <span className="mt-1 block text-xs text-muted-foreground">
                        Enter one property tag per accepted unit on a separate line. Put an optional serial number after a comma.
                    </span>
                </label>
            )}
            <div className="space-y-2 sm:col-span-2">
                {Object.entries(form.errors).map(([key, message]) => <InputError key={key} message={message} />)}
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save accepted delivery'}</Button>
            </div>
        </form>
    );
}

const actualQuarter = (date: string) => Math.ceil(Number(date.slice(5, 7)) / 3);

export default function PpmpReceiving({ item, sheet, office, quarter, planned, orders, ppmpUrl, canOrder, canReceive, today, message }: Props) {
    const endpoint = `/inventory/ppmp-items/${item.id}/quarters/${quarter}`;
    const ordered = orders.reduce((total, order) => total + order.quantity, 0);
    const received = orders.reduce((total, order) => total + order.received, 0);
    const counts = [
        ['Planned', planned], ['Ordered', ordered], ['Received', received], ['Planned balance', planned - received],
    ] as const;

    return (
        <AppLayout breadcrumbs={[{ title: 'PPMP', href: ppmpUrl }, { title: 'Orders and receipts', href: endpoint }]}>
            <Head title={item.name + ' · Orders and receipts'} />
            <main className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header>
                    <div className="flex flex-wrap gap-3 text-sm text-blue-700">
                        <Link href={ppmpUrl} className="underline">Back to PPMP</Link>
                        {(canReceive || canOrder) && <Link href="/inventory/purchase-orders" className="underline">Linked purchase orders</Link>}
                    </div>
                    <h1 className="mt-2 text-2xl font-bold">{item.name}</h1>
                    <p className="text-sm text-muted-foreground">
                        {item.code} · {office} · {sheet.reference} · Planned Q{quarter} {sheet.fiscal_year}
                    </p>
                </header>
                {message && <p role="status" className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{message}</p>}
                <dl className="grid grid-cols-2 gap-3 rounded-xl border bg-card p-4 sm:grid-cols-4">
                    {counts.map(([label, count]) => (
                        <div key={label}><dt className="text-sm text-muted-foreground">{label}</dt><dd className="font-semibold">{count} {item.unit}</dd></div>
                    ))}
                </dl>
                {canOrder && ordered < planned && <OrderForm endpoint={endpoint} available={planned - ordered} today={today} />}
                {orders.length === 0 && <p className="rounded-xl border bg-card p-4 text-sm">No purchase order has been linked to this planned item yet.</p>}
                {orders.map((order) => (
                    <section key={order.id} className="space-y-3 rounded-xl border bg-card p-4">
                        <h2 className="font-semibold">PO {order.reference}</h2>
                        <p className="text-sm text-muted-foreground">
                            Dated {order.ordered_on} · {order.received} of {order.quantity} {item.unit} received · {order.quantity - order.received} pending
                        </p>
                        {order.receipts.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted">
                                        <tr>{['Delivery reference', 'Date received', 'Actual quarter', 'Accepted'].map((heading) =>
                                            <th key={heading} scope="col" className="p-2">{heading}</th>
                                        )}</tr>
                                    </thead>
                                    <tbody>
                                        {order.receipts.map((receipt) => (
                                            <tr key={receipt.id} className="border-t">
                                                <th scope="row" className="p-2 font-medium">{receipt.reference}</th>
                                                <td className="p-2">{receipt.received_on}</td>
                                                <td className="p-2">Q{actualQuarter(receipt.received_on)} {receipt.received_on.slice(0, 4)}</td>
                                                <td className="p-2">{receipt.quantity} {item.unit}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {canReceive && order.received < order.quantity && <ReceiptForm order={order} endpoint={endpoint} today={today} />}
                    </section>
                ))}
            </main>
        </AppLayout>
    );
}
