import InputError from '@/components/input-error';
import InventoryPagination, { type Paginated } from '@/components/inventory/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';

type Office = { id: number; code: string; name: string };
type Filters = { office_id: number | null; year: number | null; quarter: number | null; search: string };
type Asset = {
    id: number;
    property_tag: string;
    name: string;
    office: string;
    received_on: string;
    receipt_reference: string | null;
    serial_number?: string | null;
    holder_name?: string | null;
};
type Props = {
    offices: Office[];
    assets: Paginated<Asset>;
    filters: Filters;
    department: boolean;
    receivingView: boolean;
    canManage: boolean;
};

const input = 'mt-1 block w-full rounded-lg border border-input bg-background px-3 py-2 text-sm';

function inventoryUrl(filters: Filters) {
    const query = new URLSearchParams();
    if (filters.office_id !== null) query.set('office_id', String(filters.office_id));
    if (filters.year !== null) query.set('year', String(filters.year));
    if (filters.quarter !== null) query.set('quarter', String(filters.quarter));
    if (filters.search) query.set('search', filters.search);
    return query.size ? '/inventory?' + query.toString() : '/inventory';
}

function RegisterEquipment({ offices, officeId }: { offices: Office[]; officeId: number | null }) {
    return (
        <Dialog>
            <DialogTrigger asChild><Button>Register equipment</Button></DialogTrigger>
            <DialogContent className="max-h-[90dvh] w-[calc(100%-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Register equipment</DialogTitle>
                    <DialogDescription>Create one record for each physical unit.</DialogDescription>
                </DialogHeader>
                <RegisterForm offices={offices} officeId={officeId} />
            </DialogContent>
        </Dialog>
    );
}

function RegisterForm({ offices, officeId }: { offices: Office[]; officeId: number | null }) {
    const form = useForm({
        property_tag: '', name: '', serial_number: '', received_on: '', receipt_reference: '',
        office_id: officeId === null ? '' : String(officeId),
    });

    return (
        <form className="space-y-3" onSubmit={(event) => {
            event.preventDefault();
            form.post('/inventory');
        }}>
            <label className="block text-sm font-medium">
                Property tag
                <input className={input} value={form.data.property_tag} maxLength={80} required
                    onChange={(event) => form.setData('property_tag', event.target.value)} />
                <InputError message={form.errors.property_tag} />
            </label>
            <label className="block text-sm font-medium">
                Equipment name
                <input className={input} value={form.data.name} maxLength={150} required
                    onChange={(event) => form.setData('name', event.target.value)} />
                <InputError message={form.errors.name} />
            </label>
            <label className="block text-sm font-medium">
                Serial number (optional)
                <input className={input} value={form.data.serial_number} maxLength={120}
                    onChange={(event) => form.setData('serial_number', event.target.value)} />
                <InputError message={form.errors.serial_number} />
            </label>
            <label className="block text-sm font-medium">
                Date received
                <input type="date" className={input} value={form.data.received_on} required
                    onChange={(event) => form.setData('received_on', event.target.value)} />
                <InputError message={form.errors.received_on} />
            </label>
            <label className="block text-sm font-medium">
                Received for office
                <select className={input} value={form.data.office_id} required
                    onChange={(event) => form.setData('office_id', event.target.value)}>
                    <option value="">Select an office</option>
                    {offices.map((office) => <option key={office.id} value={office.id}>{office.name}</option>)}
                </select>
                <InputError message={form.errors.office_id} />
            </label>
            <label className="block text-sm font-medium">
                Delivery or PO reference (optional)
                <input className={input} value={form.data.receipt_reference} maxLength={120}
                    onChange={(event) => form.setData('receipt_reference', event.target.value)} />
                <InputError message={form.errors.receipt_reference} />
            </label>
            <DialogFooter className="gap-2">
                <DialogClose asChild><Button type="button" variant="outline">Cancel</Button></DialogClose>
                <Button type="submit" disabled={form.processing}>{form.processing ? 'Registering…' : 'Register equipment'}</Button>
            </DialogFooter>
        </form>
    );
}

function InventoryFilters({ filters }: { filters: Filters }) {
    const form = useForm({
        office_id: filters.office_id,
        year: filters.year === null ? '' : String(filters.year),
        quarter: filters.quarter === null ? '' : String(filters.quarter),
        search: filters.search,
    });

    return (
        <form className="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                form.get('/inventory', { preserveState: 'errors' });
            }}>
            <label className="block text-sm font-medium">
                Search equipment
                <input type="search" className={input} maxLength={120} value={form.data.search}
                    placeholder="Name, tag or reference" onChange={(event) => form.setData('search', event.target.value)} />
                <InputError message={form.errors.search} />
            </label>
            <label className="block text-sm font-medium">
                Year received
                <input type="number" min={1900} max={2100} placeholder="All years" className={input}
                    value={form.data.year} onChange={(event) => {
                        const year = event.target.value;
                        form.setData((data) => ({ ...data, year, quarter: year ? data.quarter : '' }));
                    }} />
                <InputError message={form.errors.year} />
            </label>
            <label className="block text-sm font-medium">
                Quarter received
                <select className={input} value={form.data.quarter} disabled={!form.data.year}
                    onChange={(event) => form.setData('quarter', event.target.value)}>
                    <option value="">{form.data.year ? 'All quarters' : 'Choose a year first'}</option>
                    {[1, 2, 3, 4].map((quarter) => <option key={quarter} value={quarter}>Q{quarter}</option>)}
                </select>
                <InputError message={form.errors.quarter} />
            </label>
            <div className="flex items-start gap-2 md:pt-6">
                <Button type="submit" disabled={form.processing}>Apply filters</Button>
                <Button type="button" variant="outline"
                    onClick={() => router.get(inventoryUrl({ office_id: filters.office_id, year: null, quarter: null, search: '' }))}>
                    Clear
                </Button>
            </div>
        </form>
    );
}

export default function InventoryIndex({ offices, assets, filters, department, receivingView, canManage }: Props) {
    const title = receivingView ? 'Receiving records' : department ? 'My office inventory' : 'Inventory';
    const selectedOffice = offices.find((office) => office.id === filters.office_id);
    const officeOptions = [
        { id: null, name: 'All offices' },
        ...offices,
    ];

    return (
        <AppLayout breadcrumbs={[{ title, href: '/inventory' }]}>
            <Head title={title} />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">{title}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {receivingView ? 'Equipment recorded as received by each office.' : 'Equipment and current holders by office.'}
                        </p>
                    </div>
                    {canManage && offices.length > 0 && <RegisterEquipment offices={offices} officeId={filters.office_id} />}
                </header>

                {department && !selectedOffice ? (
                    <p className="rounded-xl border bg-card p-5 text-sm">Ask an administrator to assign your account to an office.</p>
                ) : (
                    <div className={'grid gap-5 ' + (department ? '' : 'lg:grid-cols-[14rem_minmax(0,1fr)]')}>
                        {!department && (
                            <nav aria-label="Inventory offices" className="self-start rounded-xl border bg-card p-3">
                                <h2 className="px-2 pb-2 text-sm font-semibold">Offices</h2>
                                <ul className="max-h-64 space-y-1 overflow-y-auto lg:max-h-[65dvh]">
                                    {officeOptions.map((office) => (
                                        <li key={office.id ?? 'all'}>
                                            <Link href={inventoryUrl({ ...filters, office_id: office.id })}
                                                aria-current={office.id === filters.office_id ? 'page' : undefined}
                                                className={'block rounded-lg px-2 py-2 text-sm hover:bg-muted ' +
                                                    (office.id === filters.office_id ? 'bg-blue-50 font-semibold text-blue-900' : '')}>
                                                {office.name}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                        )}
                        <div className="min-w-0 space-y-4">

                            <InventoryFilters key={`${filters.office_id}-${filters.year}-${filters.quarter}-${filters.search}`} filters={filters} />
                            <section className="overflow-hidden rounded-xl border bg-card">
                                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                                    <h2 className="font-semibold">{selectedOffice?.name ?? 'All offices'}</h2>
                                    <span className="text-sm text-muted-foreground">{assets.total} equipment {assets.total === 1 ? 'record' : 'records'}</span>
                                </div>
                                {assets.data.length === 0 ? (
                                    <p className="p-5 text-sm text-muted-foreground">No equipment matches these filters.</p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-muted">
                                                <tr>
                                                    <th scope="col" className="px-4 py-3">Equipment</th>
                                                    <th scope="col" className="px-4 py-3">{receivingView ? 'Received for' : 'Current office'}</th>
                                                    {!receivingView && <th scope="col" className="px-4 py-3">Current holder</th>}
                                                    <th scope="col" className="px-4 py-3">Date received</th>
                                                    <th scope="col" className="px-4 py-3">Reference</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {assets.data.map((asset) => (
                                                    <tr key={asset.id} className="border-t align-top">
                                                        <th scope="row" className="px-4 py-3 font-medium">
                                                            {receivingView ? asset.name : (
                                                                <Link href={'/inventory/' + asset.id} className="font-semibold text-blue-700 underline">{asset.name}</Link>
                                                            )}
                                                            <div className="text-xs text-muted-foreground">{asset.property_tag}</div>
                                                        </th>
                                                        <td className="px-4 py-3">{asset.office}</td>
                                                        {!receivingView && <td className="px-4 py-3">{asset.holder_name ?? 'Available'}</td>}
                                                        <td className="whitespace-nowrap px-4 py-3">{asset.received_on}</td>
                                                        <td className="px-4 py-3">{asset.receipt_reference ?? '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>
                            <InventoryPagination results={assets} />
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
