import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronDown, ClipboardList, Coins, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';

type Ppa = {
    id: number;
    reference: string;
    title: string;
    status: string;
    total_cents: number;
    can_edit: boolean;
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
        allocated_cents: number;
        remaining_cents: number | null;
    };
    isProgram: boolean;
    program: { id: number; reference: string; title: string } | null;
    editingId: number | null;
    readOnly: boolean;
    editorVisible: boolean;
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

function Editor({
    initial,
    editingId,
    isProgram,
    program,
    readOnly,
    listUrl,
}: Pick<Props, 'initial' | 'editingId' | 'isProgram' | 'program' | 'readOnly' | 'listUrl'>) {
    const form = useForm<Record<string, string>>(initial);

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (form.isDirty && !readOnly) {
                event.preventDefault();
                event.returnValue = '';
            }
        };

        window.addEventListener('beforeunload', warn);

        const stop = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && form.isDirty && !readOnly && !window.confirm('Leave without saving your changes?')) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            stop();
        };
    }, [form.isDirty, readOnly]);

    const total = amountFields.reduce((sum, [key]) => sum + Math.round((Number(form.data[key]) || 0) * 100), 0);

    const textFields = isProgram
        ? [
              ['reference', 'Program reference code'],
              ['title', 'Program heading'],
          ]
        : [
              ['reference', 'PPA reference code'],
              ['title', 'PPA title'],
              ['fund_source', 'Fund source'],
              ['sector', 'Sector (optional)'],
              ['sub_sector', 'Sub-sector (optional)'],
              ['division', 'Division (optional)'],
          ];

    return (
        <section className="rounded-xl border border-t-4 border-slate-200 border-t-blue-600 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-semibold">
                {readOnly ? 'View' : editingId ? 'Edit' : 'Add'} {isProgram ? 'program heading' : 'PPA'}
            </h2>

            {isProgram ? (
                <p className="mt-2 text-sm">This is a heading only. Its total is calculated from the PPAs underneath it.</p>
            ) : (
                <p className="mt-2 rounded-lg bg-green-50 p-3 text-green-950">
                    Program: {program?.reference} — {program?.title}
                </p>
            )}

            <form
                className="mt-5 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (readOnly) return;

                    if (editingId) {
                        form.put(`/aips/${editingId}`, { preserveState: false });
                    } else {
                        form.post('/aips', { preserveState: false });
                    }
                }}
            >
                <fieldset disabled={readOnly || form.processing} className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        {textFields.map(([key, label]) => (
                            <label key={key} className="block text-sm">
                                {label}
                                <input
                                    className={inputClass}
                                    value={form.data[key] ?? ''}
                                    maxLength={key === 'reference' ? 100 : 255}
                                    required={['reference', 'title', 'fund_source'].includes(key)}
                                    onChange={(event) => form.setData(key, event.target.value)}
                                />
                            </label>
                        ))}
                    </div>

                    {!isProgram && (
                        <>
                            <div className="grid gap-4 md:grid-cols-2">
                                {[
                                    ['start_date', 'Start date'],
                                    ['end_date', 'End date'],
                                ].map(([key, label]) => (
                                    <label key={key} className="block text-sm">
                                        {label}
                                        <input
                                            className={inputClass}
                                            type="date"
                                            required
                                            min={`${initial.fiscal_year}-01-01`}
                                            max={`${initial.fiscal_year}-12-31`}
                                            value={form.data[key]}
                                            onChange={(event) => form.setData(key, event.target.value)}
                                        />
                                    </label>
                                ))}
                            </div>

                            {[
                                ['description', 'Description'],
                                ['expected_output', 'Expected output'],
                            ].map(([key, label]) => (
                                <label key={key} className="block text-sm">
                                    {label}
                                    <textarea
                                        className={inputClass}
                                        rows={3}
                                        maxLength={5000}
                                        value={form.data[key]}
                                        onChange={(event) => form.setData(key, event.target.value)}
                                    />
                                </label>
                            ))}

                            <fieldset className="rounded-lg border p-4">
                                <legend className="px-2 font-semibold">PPA allocation in PHP</legend>

                                <div className="grid gap-4 md:grid-cols-2">
                                    {amountFields.map(([key, label]) => (
                                        <label key={key} className="block text-sm">
                                            {label}
                                            <input
                                                className={inputClass}
                                                type="number"
                                                min="0"
                                                max="10000000000"
                                                step="0.01"
                                                required
                                                value={form.data[key]}
                                                onChange={(event) => form.setData(key, event.target.value)}
                                            />
                                        </label>
                                    ))}
                                </div>

                                <p className="mt-4 font-semibold">Total PPA allocation: {money(total)}</p>
                                <p className="mt-1 text-sm">This allocation uses your office’s budget for the year.</p>
                            </fieldset>
                        </>
                    )}
                </fieldset>

                {Object.entries(form.errors).map(([key, error]) => (
                    <p key={key} role="alert" className="text-sm text-red-700">
                        {error}
                    </p>
                ))}

                <div className="flex items-center gap-4">
                    {!readOnly && (
                        <button className={buttonClass} disabled={form.processing}>
                            {form.processing ? 'Saving…' : isProgram ? 'Save heading' : 'Save PPA'}
                        </button>
                    )}
                    <Link href={listUrl} className="text-blue-700 underline">
                        {readOnly ? 'Back' : 'Cancel'}
                    </Link>
                </div>
            </form>
        </section>
    );
}

export default function Ppas(props: Props) {
    const { document, year, canWrite, listUrl, workspaceUrl, plans, budget, editorVisible, message } = props;

    const [collapsedPrograms, setCollapsedPrograms] = useState<number[]>([]);
    function toggleProgram(id: number) {
        setCollapsedPrograms((current) => (current.includes(id) ? current.filter((programId) => programId !== id) : [...current, id]));
    }

    const allocatedPercent = budget.ceiling_cents !== null && budget.ceiling_cents > 0 ? (budget.allocated_cents / budget.ceiling_cents) * 100 : null;
    const [filterYear, setFilterYear] = useState(String(year));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Annual investment plans', href: `/aips?year=${year}` },
                { title: `${document.office.code} — ${year}`, href: listUrl },
            ]}
        >
            <Head title={`${document.office.code} — AIP ${year}`} />

            <div className="aip-workspace min-w-0 flex-1 space-y-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">{document.title}</h1>
                        <p className="mt-1 text-sm text-slate-600">
                            {document.office.code} · {document.office.name}
                        </p>
                    </div>

                    <form
                        className="flex items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(workspaceUrl, { year: filterYear });
                        }}
                    >
                        <label>
                            Planning year
                            <input
                                className="mt-1 block w-28 rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900"
                                type="number"
                                min="2020"
                                max="2100"
                                required
                                value={filterYear}
                                onChange={(event) => setFilterYear(event.target.value)}
                            />
                        </label>
                        <button className={buttonClass}>Show</button>
                    </form>
                </header>

                <section aria-label="Office budget summary" className="grid gap-3 md:grid-cols-3">
                    {(
                        [
                            { label: 'Office budget', value: budget.ceiling_cents, Icon: Wallet, iconStyle: 'bg-blue-700 text-white' },
                            { label: 'Allocated to PPAs', value: budget.allocated_cents, Icon: ClipboardList, iconStyle: 'bg-green-700 text-white' },
                            {
                                label: 'Remaining office budget',
                                value: budget.remaining_cents,
                                Icon: Coins,
                                iconStyle: 'bg-amber-400 text-amber-950',
                            },
                        ] as const
                    ).map(({ label, value, Icon, iconStyle }) => (
                        <div key={label} className="flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className={`grid size-10 shrink-0 place-items-center rounded-lg ${iconStyle}`}>
                                <Icon aria-hidden="true" className="size-5" />
                            </div>
                            <div className="min-w-0">
                                <p className="text-sm text-slate-600">{label}</p>
                                <p className="mt-1 text-lg font-bold break-words text-slate-900 tabular-nums xl:text-xl">
                                    {value === null ? 'Not assigned' : money(value)}
                                </p>
                            </div>
                        </div>
                    ))}
                </section>

                <section aria-label="Office budget allocation" className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                    {allocatedPercent !== null && (
                        <>
                            <div className="mb-2 flex items-center justify-between gap-2 text-sm">
                                <span className="font-medium text-slate-700">Office budget allocated</span>
                                <strong className={allocatedPercent > 100 ? 'text-red-700' : 'text-blue-800'}>
                                    {allocatedPercent.toLocaleString('en-PH', { maximumFractionDigits: 1 })}%
                                </strong>
                            </div>
                            <progress
                                aria-label="Percentage of office budget allocated to PPAs"
                                aria-valuetext={allocatedPercent.toLocaleString('en-PH', { maximumFractionDigits: 1 }) + '% allocated'}
                                className="aip-allocation-progress"
                                max={100}
                                value={Math.min(100, Math.max(0, allocatedPercent))}
                            />
                        </>
                    )}
                    <p className="mt-1 text-xs text-slate-600">Allocations include draft PPAs.</p>
                </section>

                {budget.ceiling_cents === null && (
                    <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        You can organize program headings now. The Budget Office must assign a budget before you save PPAs.
                    </p>
                )}

                {message && (
                    <p role="status" className="rounded-lg bg-blue-50 p-4 text-blue-950">
                        {message}
                    </p>
                )}

                {canWrite && !editorVisible && (
                    <Link href={`${listUrl}&create=1`} className={`${buttonClass} inline-block`}>
                        + Add program heading
                    </Link>
                )}

                {editorVisible && (
                    <Editor
                        key={`${props.editingId ?? 'new'}-${props.program?.id ?? 'program'}-${props.initial.version}-${year}`}
                        initial={props.initial}
                        editingId={props.editingId}
                        isProgram={props.isProgram}
                        program={props.program}
                        readOnly={props.readOnly}
                        listUrl={listUrl}
                    />
                )}

                {plans.data.length === 0 && (
                    <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
                        {canWrite ? 'No program headings yet. Add a heading, then add its PPAs.' : 'No program headings for this office and year.'}
                    </p>
                )}

                {plans.data.map((program) => (
                    <section key={program.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="space-y-3 border-b border-slate-200 bg-blue-50 px-4 py-4 text-blue-950 md:px-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="mb-1 text-xs font-semibold text-blue-700">{program.reference}</p>
                                    <h2>
                                        <button
                                            type="button"
                                            aria-expanded={!collapsedPrograms.includes(program.id)}
                                            aria-controls={`program-ppas-${program.id}`}
                                            onClick={() => toggleProgram(program.id)}
                                            className="flex items-center gap-2 rounded text-left text-base font-bold text-slate-900 hover:text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600"
                                        >
                                            <ChevronDown
                                                aria-hidden="true"
                                                className={`size-4 shrink-0 motion-safe:transition-transform ${collapsedPrograms.includes(program.id) ? '-rotate-90' : ''}`}
                                            />
                                            {program.title}
                                        </button>
                                    </h2>
                                    <p className="mt-1 text-sm text-slate-600">
                                        {program.children.length} {program.children.length === 1 ? 'PPA' : 'PPAs'} · Program total{' '}
                                        <strong>{money(program.total_cents)}</strong>
                                    </p>
                                </div>

                                {canWrite && (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <Link
                                            href={`/aips/${program.id}/ppas/create`}
                                            aria-label={`Add PPA under ${program.title}`}
                                            className="rounded-lg bg-green-700 px-3 py-2 text-sm font-semibold text-white hover:bg-green-800"
                                        >
                                            + Add PPA
                                        </Link>
                                        <Link href={`/aips/${program.id}/edit`} className="text-sm font-medium text-blue-700 underline">
                                            Edit heading
                                        </Link>
                                    </div>
                                )}
                            </div>

                            {program.legacy_total_cents > 0 && (
                                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                                    Previous heading amount: {money(program.legacy_total_cents)}. Active allocations are recorded on the PPAs below.
                                </p>
                            )}
                        </div>

                        <div id={`program-ppas-${program.id}`} hidden={collapsedPrograms.includes(program.id)}>
                            {program.children.length === 0 ? (
                                <p className="p-5 text-sm">No PPAs under this heading yet.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead>
                                            <tr>
                                                <th className="p-3">PPA</th>
                                                <th className="p-3">Status</th>
                                                <th className="p-3">Allocation</th>
                                                <th className="p-3">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {program.children.map((ppa) => (
                                                <tr key={ppa.id} className="border-t">
                                                    <td className="p-3 pl-8">
                                                        <p className="font-semibold">{ppa.reference}</p>
                                                        <p>{ppa.title}</p>
                                                    </td>
                                                    <td className="p-3 capitalize">{ppa.status}</td>
                                                    <td className="p-3">{money(ppa.total_cents)}</td>
                                                    <td className="space-x-4 p-3">
                                                        <Link href={`/aips/${ppa.id}/view`} className="text-blue-700 underline">
                                                            View
                                                        </Link>
                                                        {ppa.can_edit && (
                                                            <Link href={`/aips/${ppa.id}/edit`} className="text-blue-700 underline">
                                                                Edit
                                                            </Link>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </section>
                ))}

                <div className="flex items-center justify-between">
                    <p>
                        Page {plans.current_page} of {plans.last_page}
                    </p>
                    <div className="flex gap-4 text-blue-700">
                        {plans.prev_page_url && <Link href={plans.prev_page_url}>Previous</Link>}
                        {plans.next_page_url && <Link href={plans.next_page_url}>Next</Link>}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
