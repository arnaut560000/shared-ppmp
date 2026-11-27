import { Errors, field, Pager, type Page, panel, peso, Success, workTitle, YearFilter } from '@/components/procurement/workflow-controls';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

type AppRecord = { id: number; version: number; status: 'draft' | 'returned' | 'submitted' | 'approved'; review_note: string | null };
type Line = {
    id: number; office: string; ppmp_reference: string; code: string; name: string; specification: string | null;
    unit: string; expense_class: string; quarter: number; quantity: number; price_cents: number;
};
type Props = {
    year: number; app: AppRecord | null; totalCents: number; lines: Page<Line>;
    canBuild: boolean; canApprove: boolean; message: string | null;
};

const url = '/procurement/app';
const labels: Record<AppRecord['status'], string> = {
    draft: 'Draft', returned: 'Returned to BAC', submitted: 'Awaiting Mayor approval', approved: 'Approved',
};

function AppActions({ year, app, canBuild, canApprove }: Pick<Props, 'year' | 'app' | 'canBuild' | 'canApprove'>) {
    const build = useForm({});
    const decision = useForm({ action: 'submit' as 'submit' | 'approve' | 'return', version: app?.version ?? 0, comment: '' });

    function decide(action: typeof decision.data.action) {
        decision.transform((data) => ({ ...data, action }));
        decision.post(url + '/' + year + '/decision');
    }

    return (
        <div className="space-y-3">
            {canApprove && (
                <label className="block text-sm font-medium">
                    Remarks (required when returning)
                    <textarea rows={3} className={field} maxLength={2000}
                        value={decision.data.comment} onChange={(event) => decision.setData('comment', event.target.value)} />
                </label>
            )}
            <div className="flex flex-wrap gap-2">
                {canBuild && (
                    <Button variant={app ? 'outline' : 'default'} disabled={build.processing || decision.processing}
                        onClick={() => build.post(url + '/' + year + '/build')}>
                        {app ? 'Rebuild from approved PPMPs' : 'Build APP from approved PPMPs'}
                    </Button>
                )}
                {canBuild && app && (
                    <Button disabled={decision.processing || build.processing} onClick={() => decide('submit')}>Submit to Mayor</Button>
                )}
                {canApprove && <>
                    <Button disabled={decision.processing} onClick={() => decide('approve')}>Approve APP</Button>
                    <Button variant="outline" disabled={decision.processing || !decision.data.comment.trim()}
                        onClick={() => decide('return')}>Return to BAC</Button>
                </>}
            </div>
            <Errors errors={build.errors} />
            <Errors errors={decision.errors} />
        </div>
    );
}

export default function AnnualProcurementPlan({ year, app, totalCents, lines, canBuild, canApprove, message }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Annual Procurement Plan', href: url }]}>
            <Head title="Annual Procurement Plan" />
            <main className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className={workTitle}>Annual Procurement Plan</h1>
                        <p className="text-sm text-muted-foreground">FY {year} · {app ? labels[app.status] : 'No APP available'}</p>
                    </div>
                    <YearFilter key={year} year={year} path={url} />
                </header>
                <Success message={message} />
                {app?.review_note && <p className="whitespace-pre-wrap rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">{app.review_note}</p>}
                {(canBuild || canApprove) && (
                    <section className={panel}>
                        <AppActions key={`${year}-${app?.version ?? 0}`} year={year} app={app} canBuild={canBuild} canApprove={canApprove} />
                    </section>
                )}
                {app && (
                    <section className={panel}>
                        <div className="flex flex-wrap justify-between gap-2">
                            <h2 className="font-semibold">PPMP source lines</h2>
                            <span className="text-sm">{lines.total} lines · {peso(totalCents)}</span>
                        </div>
                        {lines.data.length === 0 ? <p className="text-sm text-muted-foreground">No APP lines for your office.</p> : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted">
                                        <tr>{['Item', 'Office / PPMP', 'Planned quarter', 'Quantity', 'Estimated cost'].map((label) =>
                                            <th key={label} scope="col" className="p-2">{label}</th>
                                        )}</tr>
                                    </thead>
                                    <tbody>
                                        {lines.data.map((line) => (
                                            <tr key={line.id} className="border-t align-top">
                                                <th scope="row" className="p-2 font-medium">
                                                    {line.code} · {line.name}
                                                    <span className="block text-xs text-muted-foreground">
                                                        {line.expense_class}{line.specification && ' · ' + line.specification}
                                                    </span>
                                                </th>
                                                <td className="p-2">{line.office}<br />{line.ppmp_reference}</td>
                                                <td className="p-2">Q{line.quarter}</td>
                                                <td className="p-2">{line.quantity} {line.unit}</td>
                                                <td className="p-2">{peso(line.quantity * line.price_cents)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <Pager page={lines} />
                    </section>
                )}
            </main>
        </AppLayout>
    );
}
