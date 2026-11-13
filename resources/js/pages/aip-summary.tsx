import AnnualReviewPanel, { type ReviewProps } from '@/components/annual-review-panel';
import { money } from '@/components/procurement/schedule';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

type Ppa = {
    id: number;
    reference: string;
    title: string;
    status: string;
    total_cents: number;
    procurement_cents: number;
    item_count: number;
    expected_output: string | null;
    performance_indicator: string | null;
    physical_target: string | null;
};
type Props = ReviewProps & {
    document: { id: number; title: string; description: string | null; fiscal_year: number; office: { name: string } };
    programs: { id: number; reference: string; title: string; ppas: Ppa[] }[];
    ceiling: number | null;
};

export default function Summary({ document, programs, ceiling, history, summaryToken }: Props) {
    const activities = programs.flatMap((p) => p.ppas);
    const allocated = activities.reduce((sum, p) => sum + p.total_cents, 0);
    const procurement = activities.reduce((sum, p) => sum + p.procurement_cents, 0);
    const back = '/aips/documents/' + document.id;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Annual investment plan', href: back },
                { title: 'Summary', href: back + '/summary' },
            ]}
        >
            <Head title="Annual AIP summary" />
            <div className="aip-workspace min-w-0 flex-1 space-y-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header>
                    <h1 className="text-2xl font-bold text-blue-950">Annual AIP review</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        {document.office.name} · FY {document.fiscal_year} · {programs.length} PPAs · {activities.length} Sub PPAs
                    </p>
                </header>
                <Link href={back} className="inline-flex text-sm font-semibold text-blue-700 underline">Back to AIP workspace</Link>
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="font-semibold">{document.title}</h2>
                    {document.description && <p className="mt-1 text-sm whitespace-pre-line">{document.description}</p>}
                </section>
                <AnnualReviewPanel key={document.id + '-' + document.revision} document={document} activities={activities}
                    history={history} summaryToken={summaryToken} />
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Office budget', ceiling],
                        ['Sub PPA allocations', allocated],
                        ['Remaining office budget', ceiling === null ? null : ceiling - allocated],
                        ['PPMP estimated cost', procurement],
                    ].map(([label, value]) => (
                        <div key={String(label)} className="rounded border bg-white p-3">
                            <dt>{label}</dt>
                            <dd className="font-bold">{value === null ? 'Not assigned' : money(Number(value))}</dd>
                        </div>
                    ))}
                </dl>
                {programs.length === 0 && <p>No PPAs for this year.</p>}
                {programs.map((p) => (
                    <section key={p.id} className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <h2 className="bg-blue-50 p-3 font-semibold">
                            {p.reference} · {p.title}
                        </h2>
                        {!p.ppas.length ? (
                            <p className="p-3">No Sub PPAs under this PPA.</p>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        {['Sub PPA', 'Output / indicator / target', 'Allocation', 'Item lines', 'Procurement cost', 'Status'].map((h) => (
                                            <th key={h} className="p-2">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {p.ppas.map((a) => (
                                        <tr key={a.id} className="border-t">
                                            <td className="p-2">
                                                <Link href={'/aips/' + a.id + '/view'} className="text-blue-700 underline">
                                                    {a.reference} · {a.title}
                                                </Link>
                                            </td>
                                            <td className="p-2">
                                                <p>{a.expected_output}</p>
                                                {a.performance_indicator && <p>Indicator: {a.performance_indicator}</p>}
                                                {a.physical_target && <p>Target: {a.physical_target}</p>}
                                            </td>
                                            <td className="p-2">{money(a.total_cents)}</td>
                                            <td className="p-2">{a.item_count}</td>
                                            <td className="p-2">{money(a.procurement_cents)}</td>
                                            <td className="p-2">{a.status}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
