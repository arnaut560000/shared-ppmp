import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Office = { id: number; code: string; name: string };

type AipData = {
    parent_ppa_id: string;
    aip_document_id: string;
    program_name: string;
    sector: string;
    sub_sector: string;
    division: string;
    office_id: string;
    fiscal_year: string;
    reference: string;
    title: string;
    description: string;
    fund_source: string;
    expected_output: string;
    start_date: string;
    end_date: string;
    ps: string;
    mooe: string;
    fe: string;
    co: string;
    version: string;
    workflow: string;
};

type PlanRow = {
    parent_ppa_id: number | null;
    children: PlanRow[];
    id: number;
    reference: string;
    title: string;
    office: string | null;
    program_name: string | null;
    status: string;
    total_cents: number;
    can_edit: boolean;
};

type ParentPpa = { id: number; reference: string; title: string; available: Record<'ps' | 'mooe' | 'fe' | 'co', number> };

type Props = {
    parentPpa: ParentPpa | null;
    listUrl: string;
    workspaceUrl: string;
    departmentUser: boolean;
    startCreating: boolean;
    document: { id: number | null; title: string; office: Office };
    readOnly: boolean;
    totals: Record<'ps' | 'mooe' | 'fe' | 'co' | 'total', number>;
    year: number;
    canWrite: boolean;
    offices: Office[];
    plans: {
        data: PlanRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    editingId: number | null;
    initial: AipData;
    message: string | null;
};

const fieldClass =
    'mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60';
const buttonClass = 'rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50';

const amounts = [
    { key: 'ps', label: 'Personal Services (PS)' },
    { key: 'mooe', label: 'Maintenance and Other Operating Expenses (MOOE)' },
    { key: 'fe', label: 'Financial Expenses (FE)' },
    { key: 'co', label: 'Capital Outlay (CO)' },
] as const;

const fields = [
    { key: 'reference', label: 'AIP / PPA reference code', type: 'text', maxLength: 100 },
    { key: 'title', label: 'Program / project / activity title', type: 'text', maxLength: 255 },
    { key: 'fund_source', label: 'Fund source', type: 'text', maxLength: 255 },
    { key: 'start_date', label: 'Start date', type: 'date' },
    { key: 'end_date', label: 'End date', type: 'date' },
] as const;

const statusStyles: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700',
    submitted: 'bg-yellow-100 text-yellow-900',
    approved: 'bg-blue-100 text-blue-800',
    returned: 'bg-red-100 text-red-800',
};

function money(cents: number): string {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
}

function AipForm({
    initial,
    offices,
    editingId,
    readOnly,
    listUrl,
    parentPpa,
}: {
    parentPpa: ParentPpa | null;
    initial: AipData;
    offices: Office[];
    editingId: number | null;
    readOnly: boolean;
    listUrl: string;
}) {
    const form = useForm<AipData>(initial);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (form.isDirty && !readOnly) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        const stopListening = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && form.isDirty && !readOnly && !window.confirm('Leave this PPA without saving your changes?')) {
                event.preventDefault();
            }
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            stopListening();
        };
    }, [form.isDirty, readOnly]);
    const totalCents = amounts.reduce((total, field) => {
        const value = Number(form.data[field.key]);
        return total + (Number.isFinite(value) ? Math.round(value * 100) : 0);
    }, 0);

    return (
        <section className="bg-card rounded-xl border border-t-4 border-t-blue-600 p-5 shadow-sm">
            <h2 className="text-lg font-semibold">
                {readOnly
                    ? 'PPA details'
                    : editingId
                      ? parentPpa
                          ? 'Edit sub-PPA'
                          : 'Edit PPA'
                      : parentPpa
                        ? 'Add sub-PPA'
                        : 'Add a program, project or activity'}
            </h2>
            <p className="text-muted-foreground mt-1 text-sm">
                Fiscal year {initial.fiscal_year}. Enter the activity allocation; procurement requirements will be added in the next stage.
            </p>
            {parentPpa && (
                <div className="mt-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-950">
                    <p className="font-semibold">
                        Under {parentPpa.reference} — {parentPpa.title}
                    </p>
                    <p className="mt-1">This sub-PPA uses the main allocation and does not increase the annual total.</p>
                    <p className="mt-2 font-medium">Available for this sub-PPA:</p>
                    <div className="mt-1 grid gap-2 sm:grid-cols-2">
                        {amounts.map((field) => (
                            <p key={field.key}>
                                {field.key.toUpperCase()}: {money(parentPpa.available[field.key])}
                            </p>
                        ))}
                    </div>
                </div>
            )}
            <form
                className="mt-5 space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (readOnly) return;
                    if (editingId) {
                        form.put(`/aips/${editingId}`, { preserveScroll: true });
                    } else {
                        form.post('/aips', { preserveScroll: true, onSuccess: () => form.reset() });
                    }
                }}
            >
                <fieldset disabled={readOnly || form.processing} className="space-y-5">
                    <p className="rounded-lg bg-blue-50 p-3 text-sm text-blue-950">
                        Department: <strong>{offices[0]?.name}</strong>
                    </p>
                    <InputError message={form.errors.office_id} />
                    <InputError message={form.errors.fiscal_year} />
                    <div className="grid gap-4 md:grid-cols-2">
                        {(['program_name', 'sector', 'sub_sector', 'division'] as const).map((key) => (
                            <div key={key}>
                                <label htmlFor={`ppa-${key}`} className="text-sm font-medium">
                                    {
                                        {
                                            program_name: 'Parent program (optional)',
                                            sector: 'Sector (optional)',
                                            sub_sector: 'Sub-sector (optional)',
                                            division: 'Division (optional)',
                                        }[key]
                                    }
                                </label>
                                <input
                                    id={`ppa-${key}`}
                                    className={fieldClass}
                                    maxLength={255}
                                    value={form.data[key]}
                                    onChange={(e) => form.setData(key, e.target.value)}
                                />
                                <InputError message={form.errors[key]} />
                            </div>
                        ))}
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {fields.map((field) => (
                            <div key={field.key}>
                                <label htmlFor={`aip-${field.key}`} className="text-sm font-medium">
                                    {field.label}
                                </label>
                                <input
                                    id={`aip-${field.key}`}
                                    type={field.type}
                                    className={fieldClass}
                                    value={form.data[field.key]}
                                    required
                                    maxLength={'maxLength' in field ? field.maxLength : undefined}
                                    min={field.type === 'date' ? `${initial.fiscal_year}-01-01` : undefined}
                                    max={field.type === 'date' ? `${initial.fiscal_year}-12-31` : undefined}
                                    onChange={(event) => form.setData(field.key, event.target.value)}
                                />
                                <InputError message={form.errors[field.key]} className="mt-1" />
                            </div>
                        ))}
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {(
                            [
                                { key: 'description', label: 'Description' },
                                { key: 'expected_output', label: 'Expected output' },
                            ] as const
                        ).map((field) => (
                            <div key={field.key}>
                                <label htmlFor={`aip-${field.key}`} className="text-sm font-medium">
                                    {field.label}
                                </label>
                                <textarea
                                    id={`aip-${field.key}`}
                                    className={fieldClass}
                                    rows={3}
                                    maxLength={5000}
                                    value={form.data[field.key]}
                                    onChange={(event) => form.setData(field.key, event.target.value)}
                                />
                                <InputError message={form.errors[field.key]} className="mt-1" />
                            </div>
                        ))}
                    </div>
                    <fieldset className="rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Proposed amounts in PHP</legend>
                        <div className="grid gap-4 md:grid-cols-2">
                            {amounts.map((field) => (
                                <div key={field.key}>
                                    <label htmlFor={`aip-${field.key}`} className="text-sm font-medium">
                                        {field.label}
                                    </label>
                                    <input
                                        id={`aip-${field.key}`}
                                        type="number"
                                        min="0"
                                        max="10000000000"
                                        step="0.01"
                                        required
                                        className={fieldClass}
                                        value={form.data[field.key]}
                                        onChange={(event) => form.setData(field.key, event.target.value)}
                                    />
                                    <InputError message={form.errors[field.key]} className="mt-1" />
                                </div>
                            ))}
                        </div>
                        <p className="mt-5 rounded-lg bg-blue-50 p-4 text-lg font-semibold text-blue-900">Proposed total: {money(totalCents)}</p>
                    </fieldset>
                </fieldset>
                <InputError message={form.errors.aip_document_id} />
                <InputError message={form.errors.parent_ppa_id} />
                <InputError message={form.errors.workflow} />
                <InputError message={form.errors.version} />
                <div className="flex flex-wrap items-center gap-4">
                    {!readOnly && (
                        <button type="submit" className={buttonClass} disabled={form.processing}>
                            {form.processing ? 'Saving...' : editingId ? 'Save changes' : 'Save draft'}
                        </button>
                    )}
                    {(editingId || parentPpa) && (
                        <Link href={listUrl} className="text-sm font-medium text-blue-700">
                            {readOnly ? 'Back to PPA list' : 'Cancel editing'}
                        </Link>
                    )}
                </div>
            </form>
        </section>
    );
}

export default function Ppas({
    document,
    readOnly,
    totals,
    year,
    canWrite,
    offices,
    plans,
    editingId,
    initial,
    message,
    listUrl,
    workspaceUrl,
    departmentUser,
    startCreating,
    parentPpa,
}: Props) {
    const [showForm, setShowForm] = useState(startCreating);
    const [filterYear, setFilterYear] = useState(String(year));
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Annual investment plans', href: `/aips?year=${year}` },
        { title: `${document.office.code} — ${year}`, href: listUrl },
        ...(editingId ? [{ title: readOnly ? 'PPA details' : 'Edit PPA', href: `/aips/${editingId}/${readOnly ? 'view' : 'edit'}` }] : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${document.office.code} — AIP ${year}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-blue-700">DIGITALavera ePPRISM</p>
                        <h1 className="mt-1 text-2xl font-bold">{document.title}</h1>
                        <p className="text-muted-foreground mt-2 text-sm">{document.office.name} · Programs, projects and activities</p>
                    </div>
                    <div className="flex flex-wrap items-end gap-4">
                        {!departmentUser && (
                            <Link href={`/aips?year=${year}`} className="text-sm font-medium text-blue-700 underline">
                                All departments
                            </Link>
                        )}
                        {!editingId && (
                            <form
                                className="flex items-end gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(workspaceUrl, { year: filterYear });
                                }}
                            >
                                <div>
                                    <label htmlFor="ppa-year" className="text-sm font-medium">
                                        Fiscal year
                                    </label>
                                    <input
                                        id="ppa-year"
                                        type="number"
                                        min="2020"
                                        max="2100"
                                        required
                                        className={fieldClass}
                                        value={filterYear}
                                        onChange={(e) => setFilterYear(e.target.value)}
                                    />
                                </div>
                                <button className={buttonClass} type="submit">
                                    Show
                                </button>
                            </form>
                        )}
                    </div>
                </header>
                <section aria-label="PPA allocations" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {[...amounts, { key: 'total' as const, label: 'Total PPA allocation' }].map((field) => (
                        <div key={field.key} className="bg-card rounded-xl border border-t-4 border-t-blue-600 p-4">
                            <p className="text-muted-foreground text-sm">{field.label}</p>
                            <p className="mt-2 text-lg font-semibold">{money(totals[field.key])}</p>
                        </div>
                    ))}
                </section>
                <p className="text-muted-foreground text-sm">
                    Totals count main PPA allocations once, including drafts. Sub-PPAs break down those allocations.
                </p>
                {message && (
                    <div role="status" className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        {message}
                    </div>
                )}
                {canWrite && offices.length === 0 && (
                    <div role="status" className="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-950">
                        No office is available for your account. An administrator must create an office and assign office users before they can
                        prepare AIPs.
                    </div>
                )}
                {canWrite && !editingId && !showForm && (
                    <button className={`${buttonClass} self-start`} onClick={() => setShowForm(true)}>
                        Add PPA
                    </button>
                )}
                {(editingId || (canWrite && showForm)) && (
                    <AipForm
                        key={`${document.id ?? 'new-document'}-${editingId ?? 'new'}-${parentPpa?.id ?? 'main'}-${initial.version}-${year}`}
                        initial={initial}
                        editingId={editingId}
                        offices={offices}
                        readOnly={readOnly}
                        listUrl={listUrl}
                        parentPpa={parentPpa}
                    />
                )}
                <section className="bg-card overflow-hidden rounded-xl border border-t-4 border-t-yellow-400 shadow-sm">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">PPAs for {year}</h2>
                        <p className="text-muted-foreground mt-1 text-sm">{plans.total} main PPA entries. Sub-PPAs appear beneath their parent.</p>
                    </div>
                    {plans.data.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-sm">No PPAs yet. Add your first program, project or activity to get started.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-blue-50 text-blue-900">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">
                                            Reference / Title
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Office
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-5 py-3 text-right">
                                            Amount
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {plans.data
                                        .flatMap((main) => [main, ...main.children])
                                        .map((plan) => (
                                            <tr key={plan.id} className={`border-t ${plan.parent_ppa_id ? 'bg-green-50/40' : ''}`}>
                                                <td className={plan.parent_ppa_id ? 'py-4 pr-5 pl-12' : 'px-5 py-4'}>
                                                    {!plan.parent_ppa_id && <p className="text-muted-foreground text-xs">{plan.program_name}</p>}
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-semibold">
                                                            {plan.parent_ppa_id && (
                                                                <span aria-hidden="true" className="mr-2 text-green-700">
                                                                    ↳
                                                                </span>
                                                            )}
                                                            {plan.reference}
                                                        </span>
                                                        {!plan.parent_ppa_id && plan.can_edit && (
                                                            <Link
                                                                href={`/aips/${plan.id}/sub-ppas/create`}
                                                                className="rounded-md bg-green-700 px-2 py-1 text-xs font-semibold text-white hover:bg-green-800"
                                                                aria-label={`Add sub-PPA under ${plan.reference}`}
                                                            >
                                                                + Sub PPA
                                                            </Link>
                                                        )}
                                                        {plan.parent_ppa_id && (
                                                            <span className="rounded bg-green-100 px-2 py-0.5 text-xs text-green-900">Sub-PPA</span>
                                                        )}
                                                    </div>
                                                    <p className="text-muted-foreground">{plan.title}</p>
                                                </td>
                                                <td className="px-5 py-4">{plan.office}</td>
                                                <td className="px-5 py-4">
                                                    <span
                                                        className={`rounded-full px-2 py-1 text-xs font-medium capitalize ${statusStyles[plan.status] ?? 'bg-slate-100 text-slate-700'}`}
                                                    >
                                                        {plan.status}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-4 text-right whitespace-nowrap">{money(plan.total_cents)}</td>
                                                <td className="px-5 py-4">
                                                    <Link href={`/aips/${plan.id}/view`} className="mr-4 font-medium text-blue-700 underline">
                                                        View
                                                    </Link>
                                                    {plan.can_edit ? (
                                                        <Link href={`/aips/${plan.id}/edit`} className="font-medium text-blue-700 underline">
                                                            Edit
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">Read only</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div className="flex items-center justify-between border-t p-4 text-sm">
                        <span>
                            Page {plans.current_page} of {plans.last_page}
                        </span>
                        <div className="flex gap-4">
                            {plans.prev_page_url && (
                                <Link href={plans.prev_page_url} className="text-blue-700">
                                    Previous
                                </Link>
                            )}
                            {plans.next_page_url && (
                                <Link href={plans.next_page_url} className="text-blue-700">
                                    Next
                                </Link>
                            )}
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
