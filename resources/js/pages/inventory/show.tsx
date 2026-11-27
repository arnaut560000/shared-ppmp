import InputError from '@/components/input-error';
import InventoryPagination, { type Paginated } from '@/components/inventory/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';

type Office = { id: number; code: string; name: string };
type Employee = { id: number; name: string; office_id: number };
type MovementAction = 'assign' | 'transfer' | 'return';
type Asset = {
    id: number;
    property_tag: string;
    name: string;
    serial_number: string | null;
    office: string;
    office_id: number;
    received_for_office: string;
    received_on: string;
    receipt_reference: string | null;
    holder_id: number | null;
    holder_name: string | null;
    version: number;
};
type Movement = {
    id: number;
    action: MovementAction | 'registered';
    from_office: string | null;
    to_office: string;
    from_holder_name: string | null;
    to_holder_name: string | null;
    actor_name: string;
    remarks: string | null;
    recorded_at: string;
};

const movementLabels = { registered: 'Registered', assign: 'Assigned', transfer: 'Transferred', return: 'Returned' };
const input = 'mt-1 block w-full rounded-lg border border-input bg-background px-3 py-2 text-sm';

function MoveAsset({ asset, offices, employees }: { asset: Asset; offices: Office[]; employees: Employee[] }) {
    const assigned = asset.holder_name !== null;
    const form = useForm({
        action: (assigned ? 'transfer' : 'assign') as MovementAction,
        destination_office_id: String(asset.office_id),
        holder_id: '',
        remarks: '',
        version: asset.version,
        movement: '',
    });
    const sameOffice = form.data.destination_office_id === String(asset.office_id);
    const destination = form.data.action === 'transfer' ? Number(form.data.destination_office_id) : asset.office_id;
    const candidates = employees.filter((employee) => employee.office_id === destination && employee.id !== asset.holder_id);
    const needsHolder = form.data.action === 'assign' || (form.data.action === 'transfer' && sameOffice);

    return (
        <form className="space-y-4 rounded-xl border bg-card p-5" onSubmit={(event) => {
            event.preventDefault();
            form.transform(({ movement: _movement, ...data }) => ({
                ...data,
                holder_id: data.action === 'return' ? null : data.holder_id || null,
                destination_office_id: data.action === 'transfer' ? data.destination_office_id : null,
            }));
            form.post('/inventory/' + asset.id + '/movements', { preserveScroll: true });
        }}>
            <h2 className="font-semibold">Update assignment</h2>
            <label className="block text-sm font-medium">
                Action
                <select className={input} value={form.data.action} onChange={(event) => {
                    const action = event.target.value as MovementAction;
                    form.setData((data) => ({ ...data, action, holder_id: '', destination_office_id: String(asset.office_id) }));
                }}>
                    {!assigned && <option value="assign">Assign to a person</option>}
                    <option value="transfer">Transfer to another person or office</option>
                    {assigned && <option value="return">Return to available equipment</option>}
                </select>
                <InputError message={form.errors.action} />
            </label>
            {form.data.action === 'transfer' && (
                <label className="block text-sm font-medium">
                    Destination office
                    <select className={input} value={form.data.destination_office_id} onChange={(event) => {
                        const office = event.target.value;
                        form.setData((data) => ({ ...data, destination_office_id: office, holder_id: '' }));
                    }}>
                        {offices.map((office) => <option key={office.id} value={office.id}>{office.name}</option>)}
                    </select>
                    <InputError message={form.errors.destination_office_id} />
                </label>
            )}
            {form.data.action !== 'return' && (
                <label className="block text-sm font-medium">
                    {needsHolder ? 'New holder' : 'New holder (optional)'}
                    <select className={input} value={form.data.holder_id} required={needsHolder}
                        onChange={(event) => form.setData('holder_id', event.target.value)}>
                        <option value="">{needsHolder ? 'Select a person' : 'Leave available in the destination office'}</option>
                        {candidates.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
                    </select>
                    {candidates.length === 0 && <span className="mt-1 block text-xs text-muted-foreground">No eligible registered users in this office.</span>}
                    <InputError message={form.errors.holder_id} />
                </label>
            )}
            <label className="block text-sm font-medium">
                Remarks (optional)
                <textarea className={input} value={form.data.remarks} rows={3} maxLength={1000}
                    onChange={(event) => form.setData('remarks', event.target.value)} />
                <InputError message={form.errors.remarks} />
            </label>
            <InputError message={form.errors.movement ?? form.errors.version} />
            <Button type="submit" disabled={form.processing || (needsHolder && !form.data.holder_id)}>
                {form.processing ? 'Saving…' : 'Save assignment'}
            </Button>
        </form>
    );
}

function History({ history }: { history: Paginated<Movement> }) {
    const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

    return (
        <section className="space-y-4 rounded-xl border bg-card p-5">
            <h2 className="font-semibold">Assignment history</h2>
            <ol className="divide-y">
                {history.data.map((movement) => (
                    <li key={movement.id} className="space-y-1 py-3 text-sm">
                        <div className="flex flex-wrap justify-between gap-2">
                            <span className="font-semibold">{movementLabels[movement.action]}</span>
                            <time dateTime={movement.recorded_at} className="text-muted-foreground">
                                {dateTime.format(new Date(movement.recorded_at))}
                            </time>
                        </div>
                        {movement.from_office && (
                            <p>From {movement.from_office}{movement.from_holder_name && ' · ' + movement.from_holder_name}</p>
                        )}
                        <p>To {movement.to_office}{movement.to_holder_name && ' · ' + movement.to_holder_name}</p>
                        <p className="text-muted-foreground">Recorded by {movement.actor_name}</p>
                        {movement.remarks && <p className="whitespace-pre-wrap break-words">{movement.remarks}</p>}
                    </li>
                ))}
            </ol>
            <InventoryPagination results={history} />
        </section>
    );
}

export default function InventoryShow({ asset, history, offices, employees, canManage, message }: {
    asset: Asset;
    history: Paginated<Movement> | null;
    offices: Office[];
    employees: Employee[];
    canManage: boolean;
    message: string | null;
}) {
    const details = [
        ['Property tag', asset.property_tag],
        ['Current office', asset.office],
        ['Current holder', asset.holder_name ?? 'Available'],
        ['Serial number', asset.serial_number ?? '—'],
        ['Date received', asset.received_on],
        ['Received for', asset.received_for_office],
        ['Delivery or PO reference', asset.receipt_reference ?? '—'],
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'Inventory', href: '/inventory' }, { title: asset.property_tag, href: '/inventory/' + asset.id }]}>
            <Head title={asset.name} />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header>
                    <Link href={'/inventory?office_id=' + asset.office_id} className="text-sm text-blue-700 underline">Back to office inventory</Link>
                    <h1 className="mt-2 text-2xl font-bold">{asset.name}</h1>
                </header>
                {message && <p role="status" className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{message}</p>}
                <div className={'grid gap-5 ' + (canManage ? 'lg:grid-cols-2' : '')}>
                    <section className="self-start rounded-xl border bg-card p-5">
                        <h2 className="mb-4 font-semibold">Equipment details</h2>
                        <dl className="grid gap-4 sm:grid-cols-2">
                            {details.map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-sm text-muted-foreground">{label}</dt>
                                    <dd className="break-words font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    {canManage && <MoveAsset key={`${asset.id}-${asset.version}`} asset={asset} offices={offices} employees={employees} />}
                </div>
                {history && <History history={history} />}
            </div>
        </AppLayout>
    );
}
