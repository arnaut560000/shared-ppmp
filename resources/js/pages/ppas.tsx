import { sheetStatus } from '@/components/procurement/schedule';
import PlanningDeleteButton from '@/components/planning-delete-button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, ChevronDown, ChevronRight, Coins, FileText, Folder, FolderOpen, Home, Pencil, Plus, Puzzle } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

type Ppa = {
    id: number;
    reference: string;
    title: string;
    status: string;
    total_cents: number;
    description: string | null;
    item_count: number;
    can_edit: boolean;
    can_prepare_ppmp: boolean;
};

type Program = {
    id: number;
    reference: string;
    title: string;
    total_cents: number;
    legacy_total_cents: number;
    children: Ppa[];
};

type Props = {
    document: {
        id: number | null;
        revision?: number;
        description?: string | null;
        title: string;
        office: { id: number; code: string; name: string };
    };
    year: number;
    canWrite: boolean;
    listUrl: string;
    workspaceUrl: string;
    plans: {
        data: Program[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    budget: {
        ceiling_cents: number | null;
        spent_cents: number;
        remaining_cents: number | null;
    };
    isProgram: boolean;
    program: { id: number; reference: string; title: string } | null;
    editingId: number | null;
    readOnly: boolean;
    editorVisible: boolean;
    ppaCount: number;
    approvalHistory: {
        id: number;
        created_at: string;
        action: 'submit' | 'approve' | 'return';
        comment: string | null;
        reference: string;
        actor: string | null;
    }[];
    procurementLists: ProcurementList[];
    initial: Record<string, string>;
    message: string | null;
};

const inputClass =
    'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus-visible:outline-blue-600 disabled:bg-slate-50';
const buttonClass = 'rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white disabled:opacity-50';
const money = (value: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value / 100);

const amountFields = [
    ['ps', 'Personnel Services (PS)'],
    ['mooe', 'Maintenance and Other Operating Expenses (MOOE)'],
    ['fe', 'Financial Expenses (FE)'],
    ['co', 'Capital Outlay (CO)'],
] as const;

type ProcurementList = {
    id: number;
    reference: string;
    status: string;
    total_cents: number;
    items: { id: number; code: string; name: string; unit: string; quantity: number; price_cents: number; total_cents: number }[];
};

function useUnsavedPlanningEdits(dirty: boolean) {
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };

        window.addEventListener('beforeunload', warn);

        const stop = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && dirty && !window.confirm('Leave without saving your changes?')) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            stop();
        };
    }, [dirty]);
}

function AipInformation({ document, year, canWrite, onClose }: Pick<Props, 'document' | 'year' | 'canWrite'> & { onClose: () => void }) {
    const form = useForm({
        office_id: document.office.id,
        fiscal_year: year,
        title: document.title,
        description: document.description ?? '',
        revision: document.revision ?? 1,
    });
    useUnsavedPlanningEdits(form.isDirty && canWrite);
    return (
        <AipDialog
            title={document.id ? 'Edit AIP' : 'Create annual AIP'}
            onClose={() => {
                if (!form.processing && (!form.isDirty || window.confirm('Discard your unsaved changes?'))) onClose();
            }}
        >
            <form
                className="space-y-3 border-t p-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (!canWrite || form.processing) return;
                    if (document.id) form.put('/aips/documents/' + document.id, { preserveState: 'errors', onSuccess: onClose });
                    else form.post('/aips/documents', { preserveState: 'errors', onSuccess: onClose });
                }}
            >
                <p>
                    {document.office.name} · FY {year}
                </p>
                <label className="block">
                    AIP title
                    <input
                        required
                        maxLength={255}
                        className={inputClass}
                        readOnly={!canWrite}
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className="block">
                    Description
                    <textarea
                        rows={3}
                        maxLength={5000}
                        className={inputClass}
                        readOnly={!canWrite}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                    />
                </label>
                {Object.entries(form.errors).map(([key, error]) => (
                    <p className="text-red-700" key={key} role="alert">
                        {error}
                    </p>
                ))}
                {canWrite && (
                    <button className={buttonClass} disabled={form.processing}>
                        {document.id ? 'Save AIP details' : 'Create annual AIP'}
                    </button>
                )}
            </form>
        </AipDialog>
    );
}

function SavedProcurement({ lists, ppaId }: { lists: ProcurementList[]; ppaId: number }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b p-4">
                <h2 className="font-semibold">PPMP items</h2>
                <Link className="rounded border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-800" href={'/aips/' + ppaId + '/ppmp'}>
                    Open monthly PPMP
                </Link>
            </header>
            {lists.length === 0 && <p className="p-4 text-sm text-slate-600">No saved procurement lists for this Sub PPA.</p>}
            {lists.map((list) => (
                <details key={list.id} open={lists.length === 1} className="border-b last:border-0">
                    <summary className="cursor-pointer p-4 text-sm font-semibold">
                        {list.reference} · {sheetStatus(list.status)} · {money(list.total_cents)}
                    </summary>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr>
                                    {['Code', 'Item description', 'Unit', 'Qty', 'Unit cost', 'Total'].map((h) => (
                                        <th key={h} className="p-2">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {list.items.map((item) => (
                                    <tr key={item.id} className="border-t">
                                        <td className="p-2">{item.code}</td>
                                        <td>{item.name}</td>
                                        <td>{item.unit}</td>
                                        <td>{item.quantity}</td>
                                        <td>{money(item.price_cents)}</td>
                                        <td>{money(item.total_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            ))}
        </section>
    );
}

function Editor({
    initial,
    editingId,
    isProgram,
    program,
    readOnly,
    listUrl,
    budget,
    document,
    children,
}: Pick<Props, 'budget' | 'document' | 'initial' | 'editingId' | 'isProgram' | 'program' | 'readOnly' | 'listUrl'> & { children?: ReactNode }) {
    const form = useForm<Record<string, string>>(initial);

    useUnsavedPlanningEdits(form.isDirty && !readOnly);

    const total = amountFields.reduce((sum, [key]) => sum + Math.round((Number(form.data[key]) || 0) * 100), 0);

    const original = amountFields.reduce((sum, [key]) => sum + Math.round((Number(initial[key]) || 0) * 100), 0);
    const remainingAfterSave = budget.remaining_cents === null ? null : budget.remaining_cents + (editingId ? original : 0) - total;
    const title = (readOnly ? 'View ' : editingId ? 'Edit ' : 'Add ') + (isProgram ? 'PPA' : 'Sub PPA');
    const fields = (names: [string, string][], required: string[] = []) =>
        names.map(([key, label]) => (
            <label key={key} className="block text-sm font-medium">
                {label}
                <input
                    className={inputClass}
                    value={form.data[key] ?? ''}
                    maxLength={key === 'reference' ? 100 : 255}
                    required={required.includes(key)}
                    onChange={(e) => form.setData(key, e.target.value)}
                />
            </label>
        ));
    function close() {
        if (!form.processing) router.visit(listUrl);
    }
    return (
        <AipDialog title={title} onClose={close}>
            <form
                className="space-y-4 p-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (readOnly || form.processing) return;
                    const options = { preserveState: 'errors' as const };
                    if (editingId) form.put('/aips/' + editingId, options);
                    else form.post('/aips', options);
                }}
            >
                <fieldset disabled={readOnly || form.processing} className="grid gap-3">
                    {!isProgram && (
                        <div>
                            <span className="text-sm font-medium">Parent PPA</span>
                            <p className="mt-1 rounded-md border bg-slate-50 px-3 py-2">
                                {program?.reference} · {program?.title}
                            </p>
                        </div>
                    )}
                    {fields(
                        [
                            ['title', isProgram ? 'PPA title' : 'Sub PPA title'],
                            ['reference', 'Reference code'],
                        ],
                        ['title', 'reference'],
                    )}
                    {!isProgram && (
                        <>
                            <label className="block font-medium">
                                Description
                                <textarea
                                    className={inputClass}
                                    rows={2}
                                    maxLength={5000}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                />
                            </label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(['start_date', 'end_date'] as const).map((key, i) => (
                                    <label key={key}>
                                        {i ? 'End date' : 'Start date'}
                                        <input
                                            className={inputClass}
                                            type="date"
                                            value={form.data[key]}
                                            required
                                            min={i ? form.data.start_date : initial.fiscal_year + '-01-01'}
                                            max={initial.fiscal_year + '-12-31'}
                                            onChange={(e) => form.setData(key, e.target.value)}
                                        />
                                    </label>
                                ))}
                                {fields([['fund_source', 'Fund source']], ['fund_source'])}
                                <label>
                                    Responsible office
                                    <input className={inputClass + ' bg-slate-50'} value={document.office.name} readOnly />
                                </label>
                            </div>
                            {editingId !== null && (
                                <div className="grid gap-3 rounded-lg border border-blue-100 bg-blue-50/50 p-3 sm:grid-cols-2">
                                    {amountFields.map(([key, label]) => {
                                        const calculated = key === 'mooe' || key === 'co';
                                        return (
                                            <label key={key} className="text-xs">
                                                {label}
                                                {calculated ? ' · from PPMP' : ''}
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    max="10000000000"
                                                    required
                                                    readOnly={calculated}
                                                    value={form.data[key]}
                                                    className={inputClass}
                                                    onChange={(e) => form.setData(key, e.target.value)}
                                                />
                                            </label>
                                        );
                                    })}
                                    <p>
                                        <strong>Sub PPA total</strong>
                                        <br />
                                        {money(total)}
                                    </p>
                                    <p>
                                        <strong>Office balance after save</strong>
                                        <br />
                                        {remainingAfterSave === null ? 'Budget not assigned' : money(remainingAfterSave)}
                                    </p>
                                </div>
                            )}
                            <details className="rounded-lg border p-3" open={readOnly || undefined}>
                                <summary className="cursor-pointer font-semibold text-blue-900">Outputs, targets and classification</summary>
                                <div className="mt-3 grid gap-3">
                                    {['expected_output', 'performance_indicator', 'physical_target'].map((key, i) => (
                                        <label key={key}>
                                            {['Expected output', 'Performance indicator', 'Physical target'][i]}
                                            <textarea
                                                rows={2}
                                                maxLength={5000}
                                                className={inputClass}
                                                value={form.data[key]}
                                                onChange={(e) => form.setData(key, e.target.value)}
                                            />
                                        </label>
                                    ))}
                                    {fields([
                                        ['sector', 'Sector'],
                                        ['sub_sector', 'Sub-sector'],
                                        ['division', 'Division'],
                                    ])}
                                </div>
                            </details>
                        </>
                    )}
                </fieldset>
                {Object.entries(form.errors).map(([key, error]) => (
                    <p role="alert" className="text-red-700" key={key}>
                        {error}
                    </p>
                ))}
                {children}
                <footer className="flex justify-end gap-2 border-t pt-3">
                    <button type="button" className={outline} onClick={close} disabled={form.processing}>
                        {readOnly ? 'Close' : 'Cancel'}
                    </button>
                    {!readOnly && (
                        <button className={buttonClass} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save'}
                        </button>
                    )}
                </footer>
            </form>
        </AipDialog>
    );
}

const outline =
    'inline-flex items-center justify-center gap-1.5 rounded-md border border-blue-200 bg-white px-3 py-1.5 text-sm font-semibold text-blue-800 hover:bg-blue-50';
const panel = 'overflow-hidden rounded-xl border border-[#cfdeee] bg-white shadow-sm';
const panelHead = 'flex min-h-12 flex-wrap items-center justify-between gap-2 border-b border-blue-100 bg-[#edf4fd] px-4 py-2';
function AipDialog({ title, children, onClose }: { title: string; children: ReactNode; onClose: () => void }) {
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                className="block max-h-[88dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl border-blue-200 bg-white p-0 text-sm text-slate-900 [&>button]:z-[2] [&>button]:text-white"
            >
                <DialogTitle className="sticky top-0 z-[1] bg-[#103b60] px-5 py-4 pr-12 text-base font-semibold text-white">{title}</DialogTitle>
                {children}
            </DialogContent>
        </Dialog>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="mb-1 text-xs font-semibold text-slate-500">{label}</dt>
            <dd className="font-medium break-words">{children}</dd>
        </div>
    );
}

export default function Ppas(props: Props) {
    return <Workspace key={props.document.office.id + '-' + props.year + '-' + props.plans.current_page} {...props} />;
}

function Workspace(props: Props) {
    const { document, year, plans, budget, canWrite, editorVisible, listUrl, workspaceUrl } = props;
    const [openPrograms, setOpenPrograms] = useState<number[]>(plans.data.slice(0, 1).map((p) => p.id));
    const [selected, setSelected] = useState<number | null>(null);
    const [editInfo, setEditInfo] = useState(false);
    const group = plans.data.find((p) => p.id === selected || p.children.some((a) => a.id === selected));
    const activity = group?.children.find((a) => a.id === selected);
    const selectedTitle = activity?.title ?? group?.title ?? document.title;
    const summaryUrl = document.id ? '/aips/documents/' + document.id + '/summary' : null;
    return (
        <AppLayout>
            <Head title={document.office.code + ' · AIP ' + year} />
            <main className="aip-workspace min-w-0 flex-1 bg-[#f3f6fc] pb-6 text-sm text-[#142f4a]">
                <nav aria-label="Breadcrumb" className="flex items-center gap-2 border-b border-blue-100 bg-[#e7f0fc] px-4 py-2.5 text-xs md:px-6">
                    <Link href={'/aips?year=' + year} aria-label="Annual investment plans">
                        <Home size={15} />
                    </Link>
                    <ChevronRight size={12} />
                    <Link href={'/aips?year=' + year}>AIP Management</Link>
                    <ChevronRight size={12} />
                    <span>AIP workspace</span>
                </nav>
                <div className="mx-auto max-w-[1600px] space-y-4 p-3 sm:p-5">
                    <header className="flex flex-wrap items-center gap-4">
                        <div className="flex min-w-0 flex-1 items-center gap-3">
                            <CalendarDays className="size-10 shrink-0 text-blue-800" />
                            <div>
                                <h1 className="text-lg leading-tight font-bold tracking-tight text-[#102f54]">Annual Investment Program (AIP)</h1>
                                <p className="mt-1 text-slate-600">{document.office.name}</p>
                            </div>
                        </div>
                        <label className="flex items-center gap-1 rounded-md border border-blue-200 bg-white px-2 py-1.5 font-semibold">
                            FY{' '}
                            <select
                                aria-label="Fiscal year"
                                value={year}
                                onChange={(e) => router.get(workspaceUrl, { year: e.target.value })}
                            >
                                {Array.from({ length: 81 }, (_, i) => 2020 + i).map((y) => (
                                    <option key={y}>{y}</option>
                                ))}
                            </select>
                        </label>
                        {summaryUrl && <Link href={summaryUrl} className={buttonClass}>Review AIP</Link>}
                        <dl className="grid w-full grid-cols-[1.4fr_1fr_1fr] gap-2 xl:w-auto">
                            {(
                                [
                                    ['Total AIP amount', money(budget.spent_cents), Coins, 'border-blue-200 bg-blue-50 text-blue-900'],
                                    ['Total PPAs', plans.total, CheckCircle2, 'border-emerald-200 bg-emerald-50 text-emerald-900'],
                                    ['Total Sub PPAs', props.ppaCount, Puzzle, 'border-violet-200 bg-violet-50 text-violet-900'],
                                ] as const
                            ).map(([label, value, Icon, tint]) => (
                                <div key={label} className={'flex min-w-0 items-center gap-2 rounded-lg border p-2.5 sm:px-4 ' + tint}>
                                    <Icon className="hidden size-6 shrink-0 sm:block" />
                                    <div>
                                        <dt className="text-xs">{label}</dt>
                                        <dd className="font-bold tabular-nums sm:text-base">{value}</dd>
                                    </div>
                                </div>
                            ))}
                        </dl>
                    </header>
                    {props.message && (
                        <p role="status" className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2">
                            {props.message}
                        </p>
                    )}
                    {budget.ceiling_cents === null && (
                        <p role="status" className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                            The Budget Office must assign a budget for FY {year} before this office can save expenses or PPMP items.
                        </p>
                    )}
                    <div className="grid items-start gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                        <section className={panel}>
                            <header className={panelHead}>
                                <h2 className="font-bold">PPAs and Sub PPAs</h2>
                                {canWrite && (
                                    <Link className={buttonClass + ' inline-flex items-center gap-1'} href={listUrl + '&create=1'}>
                                        <Plus size={15} />
                                        Add PPA
                                    </Link>
                                )}
                            </header>
                            <div className="divide-y divide-blue-100">
                                {plans.data.length === 0 && <p className="p-5 text-slate-600">No PPAs for this fiscal year.</p>}
                                {plans.data.map((program) => {
                                    const open = openPrograms.includes(program.id);
                                    return (
                                        <div key={program.id}>
                                            <div
                                                className={
                                                    'flex items-center gap-1 p-2.5 sm:gap-2 ' +
                                                    (group?.id === program.id ? 'bg-blue-100/70' : 'bg-blue-50/50')
                                                }
                                            >
                                                <button
                                                    className="rounded p-1 hover:bg-blue-100"
                                                    aria-label={(open ? 'Collapse ' : 'Expand ') + program.title}
                                                    aria-expanded={open}
                                                    onClick={() =>
                                                        setOpenPrograms((ids) =>
                                                            open ? ids.filter((id) => id !== program.id) : [...ids, program.id],
                                                        )
                                                    }
                                                >
                                                    {open ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                                                </button>
                                                <button
                                                    className="flex min-w-0 flex-1 items-start gap-2 text-left"
                                                    onClick={() => setSelected(program.id)}
                                                >
                                                    {open ? (
                                                        <FolderOpen size={18} className="mt-0.5 shrink-0 text-blue-700" />
                                                    ) : (
                                                        <Folder size={18} className="mt-0.5 shrink-0 text-blue-700" />
                                                    )}
                                                    <span>
                                                        <strong className="break-words">{program.title}</strong>
                                                        <span className="mt-1 block text-xs">
                                                            {money(program.total_cents)}{' '}
                                                            <span className="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-blue-800">
                                                                {program.children.length} {program.children.length === 1 ? 'Sub PPA' : 'Sub PPAs'}
                                                            </span>
                                                        </span>
                                                    </span>
                                                </button>
                                                {canWrite && (
                                                    <Link
                                                        className={outline}
                                                        href={'/aips/' + program.id + '/ppas/create'}
                                                        aria-label={'Add Sub PPA under ' + program.title}
                                                    >
                                                        <Plus size={14} />
                                                        Sub PPA
                                                    </Link>
                                                )}
                                            </div>
                                            {open && (
                                                <div className="my-2 ml-6 space-y-2 border-l border-blue-200 pr-3 pl-3 sm:ml-9">
                                                    {program.children.length === 0 && (
                                                        <p className="py-2 text-xs text-slate-500">No Sub PPAs under this PPA.</p>
                                                    )}
                                                    {program.children.map((ppa) => (
                                                        <button
                                                            type="button"
                                                            aria-pressed={selected === ppa.id}
                                                            key={ppa.id}
                                                            onClick={() => setSelected(ppa.id)}
                                                            className={
                                                                'flex w-full items-start gap-2 rounded-md border p-2.5 text-left ' +
                                                                (selected === ppa.id
                                                                    ? 'border-blue-400 bg-blue-50'
                                                                    : 'border-amber-200 bg-[#fffbef] hover:border-blue-300')
                                                            }
                                                        >
                                                            <FileText size={16} className="mt-0.5 shrink-0 text-blue-600" />
                                                            <span>
                                                                <strong>{ppa.title}</strong>
                                                                <span className="mt-1 block text-xs text-slate-600">
                                                                    {money(ppa.total_cents)} · {ppa.item_count} PPMP item lines
                                                                </span>
                                                            </span>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                            {plans.last_page > 1 && (
                                <nav aria-label="PPA pages" className="flex items-center gap-3 border-t p-3 text-xs">
                                    <span>
                                        Page {plans.current_page} of {plans.last_page}
                                    </span>
                                    {plans.prev_page_url && (
                                        <Link className="text-blue-700 underline" href={plans.prev_page_url}>
                                            Previous
                                        </Link>
                                    )}
                                    {plans.next_page_url && (
                                        <Link className="text-blue-700 underline" href={plans.next_page_url}>
                                            Next
                                        </Link>
                                    )}
                                </nav>
                            )}
                        </section>
                        <section className={panel} aria-label="Selected details">
                            <header className={panelHead}>
                                <h2 className="font-bold">Details</h2>
                                {selected !== null && (
                                    <button className={outline} onClick={() => setSelected(null)}>
                                        AIP details
                                    </button>
                                )}
                                {!group && canWrite && (
                                    <button className={outline} onClick={() => setEditInfo(true)}>
                                        <Pencil size={14} />
                                        {document.id ? 'Edit AIP' : 'Create AIP'}
                                    </button>
                                )}
                            </header>
                            <div className="space-y-4 p-4">
                                <h3 className="font-bold">{selectedTitle}</h3>
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-4">
                                    <Detail label={group ? 'Reference code' : 'AIP record'}>
                                        {activity?.reference ?? group?.reference ?? (document.id ? '#' + document.id : 'Not created')}
                                    </Detail>
                                    <Detail label="Fiscal year">{year}</Detail>
                                    <Detail label="Office">{document.office.name}</Detail>
                                    {activity ? (
                                        <Detail label="Sub PPA status">
                                            <span className="rounded-full bg-blue-50 px-2 py-1 text-xs text-blue-900 capitalize">
                                                {activity.status}
                                            </span>
                                        </Detail>
                                    ) : (
                                        <Detail label={group ? 'Sub PPAs in this PPA' : 'Office budget'}>
                                            {group
                                                ? group.children.length
                                                : budget.ceiling_cents === null
                                                  ? 'Not assigned'
                                                  : money(budget.ceiling_cents)}
                                        </Detail>
                                    )}
                                    <Detail label={activity ? 'Sub PPA amount' : group ? 'PPA amount' : 'Office balance'}>
                                        {group
                                            ? money(activity?.total_cents ?? group.total_cents)
                                            : budget.remaining_cents === null
                                              ? 'Not assigned'
                                              : money(budget.remaining_cents)}
                                    </Detail>
                                    {group && group.legacy_total_cents > 0 && !activity && (
                                        <Detail label="Historical heading amount">{money(group.legacy_total_cents)}</Detail>
                                    )}
                                </dl>
                                {(!group || activity) && (
                                    <dl className="border-t pt-3">
                                        <Detail label="Description">
                                            {(activity ? activity.description : document.description) || 'No description recorded.'}
                                        </Detail>
                                    </dl>
                                )}
                                {canWrite && !group && document.id && <PlanningDeleteButton kind="aip" id={document.id} />}
                                {group && (
                                    <div className="flex flex-wrap gap-2 border-t pt-3">
                                        <Link className={outline} href={'/aips/' + (activity?.id ?? group.id) + '/view'}>
                                            View full details
                                        </Link>
                                        {(activity ? activity.can_edit : canWrite) && (
                                            <Link className={outline} href={'/aips/' + (activity?.id ?? group.id) + '/edit'}>
                                                <Pencil size={14} />
                                                Edit
                                            </Link>
                                        )}
                                        {(activity ? activity.can_edit : canWrite) && (
                                            <PlanningDeleteButton
                                                key={activity?.id ?? group.id}
                                                kind={activity ? 'sub-ppa' : 'ppa'}
                                                id={activity?.id ?? group.id}
                                            />
                                        )}
                                        {activity && (
                                            <Link className={outline} href={'/aips/' + activity.id + '/ppmp'}>
                                                Open PPMP
                                            </Link>
                                        )}
                                        {activity?.can_prepare_ppmp && (
                                            <Link className={buttonClass} href={'/aips/' + activity.id + '/ppmp?new=1'}>
                                                + Add PPMP
                                            </Link>
                                        )}
                                    </div>
                                )}
                            </div>
                        </section>
                    </div>
                    {props.approvalHistory.length > 0 && (
                        <details className={panel}>
                            <summary className="cursor-pointer p-4 font-semibold">Sub PPA decision history</summary>
                            <div className="px-4 pb-4">
                                <ol className="divide-y">
                                    {props.approvalHistory.map((event) => (
                                        <li key={event.id} className="space-y-1 py-3">
                                            <strong>
                                                {event.reference} ·{' '}
                                                {{ submit: 'Submitted', approve: 'Approved', return: 'Returned' }[event.action]}
                                            </strong>
                                            <p className="text-xs text-slate-600">
                                                {event.created_at} · {event.actor ?? 'Unknown user'}
                                            </p>
                                            {event.comment && <p>{event.comment}</p>}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </details>
                    )}
                </div>
                {editInfo && <AipInformation document={document} year={year} canWrite={canWrite} onClose={() => setEditInfo(false)} />}
                {editorVisible && (
                    <Editor key={[props.editingId, props.program?.id, props.initial.version, props.readOnly].join('-')} {...props}>
                        {props.editingId && !props.isProgram && <SavedProcurement lists={props.procurementLists} ppaId={props.editingId} />}
                    </Editor>
                )}
            </main>
        </AppLayout>
    );
}
