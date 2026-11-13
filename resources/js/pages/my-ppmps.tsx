import { money, sheetStatus } from '@/components/procurement/schedule';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Sheet = {
    id: number;
    reference: string;
    status: string;
    total_cents: number;
    office_name: string;
    activity_id: number;
    activity_title: string;
    heading_title: string;
};

type Props = {
    office: string | null;
    reviewQueue: boolean;
    year: number;
    sheets: {
        data: Sheet[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

const linkClass = 'font-semibold text-blue-700 underline underline-offset-2';

export default function MyPpmps({ office, year, sheets, reviewQueue }: Props) {
    const title = reviewQueue ? 'PPMPs for GSO review' : 'My PPMPs';
    const listUrl = reviewQueue ? '/ppmp-reviews' : '/my-ppmps';
    const [filterYear, setFilterYear] = useState(String(year));

    return (
        <AppLayout breadcrumbs={[{ title, href: `${listUrl}?year=${year}` }]}>
            <Head title={title} />

            <div className="aip-workspace min-w-0 flex-1 space-y-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-blue-950">{title}</h1>
                        <p className="mt-1 text-sm text-slate-600">
                            {office ?? 'All departments'} · FY {year}
                        </p>
                    </div>

                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(listUrl, { year: filterYear });
                        }}
                    >
                        <label className="text-sm font-medium">
                            Fiscal year
                            <input
                                type="number"
                                min="2020"
                                max="2100"
                                required
                                value={filterYear}
                                onChange={(event) => setFilterYear(event.target.value)}
                                className="mt-1 block w-28 rounded-lg border bg-white px-3 py-2"
                            />
                        </label>
                        <button className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white">
                            Show
                        </button>
                    </form>
                </header>

                <section className="overflow-hidden rounded-xl border border-slate-200 border-t-4 border-t-blue-600 bg-white shadow-sm">
                    {sheets.data.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">
                                    PPMP sheets for {office ?? 'all departments'}, FY {year}
                                </caption>
                                <thead className="bg-blue-50 text-blue-950">
                                    <tr>
                                        {['PPMP reference', ...(reviewQueue ? ['Office'] : []), 'Parent PPA', 'Sub PPA', 'Estimated cost', 'Status', 'Action'].map((heading) => (
                                            <th key={heading} scope="col" className="whitespace-nowrap px-4 py-3">
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {sheets.data.map((sheet) => (
                                        <tr key={sheet.id}>
                                            <th scope="row" className="px-4 py-3">{sheet.reference}</th>
                                            {reviewQueue && <td className="px-4 py-3">{sheet.office_name}</td>}
                                            <td className="px-4 py-3">{sheet.heading_title}</td>
                                            <td className="px-4 py-3">{sheet.activity_title}</td>
                                            <td className="whitespace-nowrap px-4 py-3 tabular-nums">
                                                {money(sheet.total_cents)}
                                            </td>
                                            <td className="px-4 py-3 capitalize">{sheetStatus(sheet.status)}</td>
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/aips/${sheet.activity_id}/ppmp?sheet=${sheet.id}`}
                                                    className={linkClass}
                                                >
                                                    Open<span className="sr-only"> {sheet.reference}</span>
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="space-y-2 p-5 text-sm">
                            <p>
                                {sheets.total === 0
                                    ? (reviewQueue ? `No PPMPs are awaiting GSO review for FY ${year}.` : `Your office has no saved PPMP sheets for FY ${year}.`)
                                    : 'There are no sheets on this page.'}
                            </p>
                            <Link href={`/aips?year=${year}`} className={linkClass}>
                                Open AIP workspace
                            </Link>
                        </div>
                    )}

                    {(sheets.prev_page_url || sheets.next_page_url) && (
                        <nav aria-label="PPMP pages" className="flex items-center gap-4 border-t px-4 py-3 text-sm">
                            <span>Page {sheets.current_page} of {sheets.last_page}</span>
                            {sheets.prev_page_url && (
                                <Link href={sheets.prev_page_url} className={linkClass}>Previous</Link>
                            )}
                            {sheets.next_page_url && (
                                <Link href={sheets.next_page_url} className={linkClass}>Next</Link>
                            )}
                        </nav>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
