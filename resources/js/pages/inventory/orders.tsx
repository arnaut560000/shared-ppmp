import InventoryPagination, { type Paginated } from '@/components/inventory/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Order = {
    id: number;
    item: string;
    unit: string;
    office: string;
    reference: string;
    quarter: number;
    year: number;
    quantity: number;
    received: number;
    url: string;
};

export default function PurchaseOrders({ orders, search }: { orders: Paginated<Order>; search: string }) {
    const [query, setQuery] = useState(search);

    return (
        <AppLayout breadcrumbs={[{ title: 'Inventory', href: '/inventory' }, { title: 'Purchase orders', href: '/inventory/purchase-orders' }]}>
            <Head title="Purchase orders and receipts" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-bold">Purchase orders and receipts</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Open a linked order to see accepted deliveries. BAC can link an order from an approved PPMP item.
                    </p>
                </header>
                <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/inventory/purchase-orders', { search: query.trim() || undefined });
                }}>
                    <label className="text-sm font-medium">
                        Search by PO reference
                        <input type="search" value={query} maxLength={120} onChange={(event) => setQuery(event.target.value)}
                            className="mt-1 block rounded-lg border bg-background px-3 py-2" />
                    </label>
                    <Button type="submit">Search</Button>
                </form>
                <section className="overflow-x-auto rounded-xl border bg-card">
                    {orders.data.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">No linked purchase orders found.</p>
                    ) : (
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted">
                                <tr>
                                    {['PO reference', 'Item', 'Office', 'Planned quarter', 'Received / ordered'].map((heading) =>
                                        <th key={heading} scope="col" className="p-3">{heading}</th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((order) => (
                                    <tr key={order.id} className="border-t">
                                        <th scope="row" className="p-3">
                                            <Link href={order.url} className="text-blue-700 underline">{order.reference}</Link>
                                        </th>
                                        <td className="p-3">{order.item}</td>
                                        <td className="p-3">{order.office}</td>
                                        <td className="p-3">Q{order.quarter} · {order.year}</td>
                                        <td className="p-3">{order.received} / {order.quantity} {order.unit}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
                <InventoryPagination results={orders} />
            </div>
        </AppLayout>
    );
}
