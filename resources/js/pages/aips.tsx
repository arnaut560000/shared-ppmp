import AppLayout from '@/layouts/app-layout';
import { planningLink, planningSection } from '@/lib/planning-navigation';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Office = { id: number; code: string; name: string };
type Document = {
    id: number;
    title: string;
    fiscal_year: number;
    office: Office;
    ppas_count: number;
    ppas_sum_total_cents: number | string | null;
};
type Props = {
    year: number;
    canWrite: boolean;
    offices: Office[];
    documents: {
        data: Document[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    message: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Annual investment plans', href: '/aips' }];
const fieldClass = 'mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
const buttonClass = 'rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50';
const money = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);

export default function Aips({ year, canWrite, offices, documents, message }: Props) {
    const section = planningSection(usePage().url);
    const choosingPpas = section === 'ppas';
    const [filterYear, setFilterYear] = useState(String(year));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Annual Investment Plans" />
            <div className="aip-workspace flex min-w-0 flex-1 flex-col gap-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="mt-1 text-2xl font-bold">{choosingPpas ? 'PPA List' : 'Annual Investment Plans'}</h1>
                        <p className="text-muted-foreground mt-2 text-sm">
                            {choosingPpas
                                ? 'Choose a department to view its PPA list.'
                                : 'View department allocations and open their programs, projects and activities.'}
                        </p>
                    </div>
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get('/aips', { year: filterYear, ...(section ? { section } : {}) });
                        }}
                    >
                        <div>
                            <label htmlFor="filter-year" className="text-sm font-medium">
                                Fiscal year
                            </label>
                            <input
                                id="filter-year"
                                type="number"
                                min="2020"
                                max="2100"
                                required
                                value={filterYear}
                                onChange={(e) => setFilterYear(e.target.value)}
                                className={fieldClass}
                            />
                        </div>
                        <button type="submit" className={buttonClass}>
                            Show
                        </button>
                    </form>
                </header>
                <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                    Each annual plan groups one office’s programs and procurement requirements for the selected year.
                </div>
                {message && (
                    <div role="status" className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        {message}
                    </div>
                )}
                {canWrite && offices.length === 0 && (
                    <div role="status" className="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-950">
                        No office is available for your account. An administrator must create an office and assign your account before you can prepare
                        an annual plan.
                    </div>
                )}
                {canWrite && offices.length > 0 && (
                    <section className="bg-card rounded-xl border p-5">
                        <h2 className="font-semibold">Open a department's planning workspace</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Administrator access. Department users work only in their assigned office.
                        </p>
                        <ul className="mt-3 divide-y">
                            {offices.map((office) => (
                                <li key={office.id} className="flex items-center justify-between gap-4 py-3">
                                    <span>
                                        {office.code} — {office.name}
                                    </span>
                                    <Link className={buttonClass} href={planningLink(`/aips/offices/${office.id}`, section ?? 'info', year)}>
                                        {choosingPpas ? 'Open PPA list' : 'Open AIP'}
                                        <span className="sr-only"> for {office.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <section className="bg-card overflow-hidden rounded-xl border border-t-4 border-t-yellow-400 shadow-sm">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">Department annual plans — {year}</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {documents.total} annual plan(s). Allocations include drafts and do not indicate approval.
                        </p>
                    </div>
                    {documents.data.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-sm">No annual plans for this year yet.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-blue-50 text-blue-900">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">
                                            Department / annual plan
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Sub PPAs
                                        </th>
                                        <th scope="col" className="px-5 py-3 text-right">
                                            Total Sub PPA allocation
                                        </th>
                                        <th scope="col" className="px-5 py-3">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {documents.data.map((document) => (
                                        <tr key={document.id} className="border-t">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold">{document.office.name}</p>
                                                <p className="text-muted-foreground">{document.title}</p>
                                            </td>
                                            <td className="px-5 py-4">{document.ppas_count}</td>
                                            <td className="px-5 py-4 text-right whitespace-nowrap">
                                                {money(Number(document.ppas_sum_total_cents ?? 0))}
                                            </td>
                                            <td className="px-5 py-4">
                                                <Link
                                                    href={planningLink(`/aips/documents/${document.id}`, choosingPpas ? 'ppas' : 'info', year)}
                                                    className="font-semibold text-blue-700 underline"
                                                >
                                                    {choosingPpas ? 'Open PPA list' : 'Open AIP'}
                                                    <span className="sr-only"> for {document.office.name}</span>
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div className="flex items-center justify-between border-t p-4 text-sm">
                        <span>
                            Page {documents.current_page} of {documents.last_page}
                        </span>
                        <div className="flex gap-4">
                            {documents.prev_page_url && (
                                <Link
                                    href={section ? planningLink(documents.prev_page_url, section) : documents.prev_page_url}
                                    className="text-blue-700 underline"
                                >
                                    Previous
                                </Link>
                            )}
                            {documents.next_page_url && (
                                <Link
                                    href={section ? planningLink(documents.next_page_url, section) : documents.next_page_url}
                                    className="text-blue-700 underline"
                                >
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
