import { money } from '@/components/procurement/schedule';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';

type Sheet = {
    id: number;
    reference: string;
    version: number;
    total_cents: number;
    office: string;
    activity_id: number;
    activity: string;
    annual_revision: number | null;
    attested: boolean;
    blocked: string | null;
};

type Props = {
    year: number;
    message: string | null;
    sheets: {
        data: Sheet[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

const primary =
    'rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white disabled:opacity-50';

const secondary =
    'rounded-lg border border-blue-200 bg-white px-4 py-2 font-semibold text-blue-800';

function DecisionCard({ sheet }: { sheet: Sheet }) {
    const form = useForm({
        action: 'approve' as 'approve' | 'return',
        version: sheet.version,
        annual_revision: sheet.annual_revision,
        comment: '',
        authorize_revision: false as boolean,
    });

    function decide(action: 'approve' | 'return') {
        form.transform((data) => ({
            ...data,
            action,
            comment: data.comment.trim(),
        }));

        form.post(`/ppmp-approvals/${sheet.id}`, {
            preserveScroll: true,
        });
    }

    return (
        <article className="space-y-4 rounded-xl border border-blue-100 bg-white p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="font-bold text-blue-950">
                        {sheet.reference}
                    </h2>

                    <p className="text-sm">
                        {sheet.office} · {sheet.activity}
                    </p>

                    <p className="mt-1 text-sm text-slate-600">
                        GSO review completed · Awaiting approval
                    </p>
                </div>

                <p className="font-bold text-blue-900">
                    {money(sheet.total_cents)}
                </p>
            </div>

            <Link
                className="inline-block font-semibold text-blue-700 underline"
                href={`/aips/${sheet.activity_id}/ppmp?sheet=${sheet.id}`}
            >
                Open saved PPMP
            </Link>

            {sheet.blocked ? (
                <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    {sheet.blocked}
                </p>
            ) : (
                <>
                    <div>
                        <label
                            className="mb-1 block text-sm font-semibold"
                            htmlFor={`comment-${sheet.id}`}
                        >
                            Remarks — required when returning
                        </label>

                        <textarea
                            id={`comment-${sheet.id}`}
                            className="w-full rounded-lg border border-blue-200 p-3"
                            rows={3}
                            maxLength={2000}
                            value={form.data.comment}
                            onChange={(event) =>
                                form.setData('comment', event.target.value)
                            }
                            disabled={form.processing}
                        />
                    </div>

                    {sheet.attested && (
                        <label className="flex items-start gap-3 rounded-lg bg-amber-50 p-3 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.authorize_revision}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData(
                                        'authorize_revision',
                                        event.target.checked,
                                    )
                                }
                            />

                            <span>
                                When returning this PPMP, authorize reopening
                                its attested annual AIP for corrections and a
                                new MPDC → Budget → Mayor review.
                            </span>
                        </label>
                    )}

                    <div className="flex flex-wrap gap-3">
                        <button
                            type="button"
                            className={primary}
                            disabled={form.processing}
                            onClick={() => decide('approve')}
                        >
                            Approve PPMP
                        </button>

                        <button
                            type="button"
                            className="rounded-lg border border-red-300 px-4 py-2 font-semibold text-red-700 disabled:opacity-50"
                            disabled={
                                form.processing ||
                                !form.data.comment.trim() ||
                                (sheet.attested &&
                                    !form.data.authorize_revision)
                            }
                            onClick={() => decide('return')}
                        >
                            Return for revision
                        </button>
                    </div>
                </>
            )}

            {Object.entries(form.errors).map(([key, error]) => (
                <p key={key} role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            ))}
        </article>
    );
}

export default function PpmpApprovals({ year, sheets, message }: Props) {
    const filter = useForm({
        year: String(year),
    });

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'PPMPs for approval',
                    href: '/ppmp-approvals',
                },
            ]}
        >
            <Head title="PPMPs for approval" />

            <div className="aip-workspace min-w-0 flex-1 space-y-5 bg-slate-50 p-4 text-slate-900 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-blue-950">
                            PPMPs for approval
                        </h1>

                        <p className="text-sm text-slate-600">
                            {sheets.total} awaiting approval · FY {year}
                        </p>
                    </div>

                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();

                            filter.get('/ppmp-approvals', {
                                preserveState: false,
                            });
                        }}
                    >
                        <label className="text-sm">
                            Fiscal year

                            <input
                                className="mt-1 block w-28 rounded-lg border border-blue-200 bg-white p-2"
                                type="number"
                                min={2020}
                                max={2100}
                                required
                                value={filter.data.year}
                                onChange={(event) =>
                                    filter.setData('year', event.target.value)
                                }
                            />
                        </label>

                        <button
                            className={secondary}
                            disabled={filter.processing}
                        >
                            Show
                        </button>
                    </form>
                </header>

                {filter.errors.year && (
                    <p role="alert" className="text-red-700">
                        {filter.errors.year}
                    </p>
                )}

                {message && (
                    <p
                        role="status"
                        className="rounded-lg border border-green-200 bg-green-50 p-3"
                    >
                        {message}
                    </p>
                )}

                {sheets.data.map((sheet) => (
                    <DecisionCard
                        key={`${sheet.id}-${sheet.version}-${sheet.annual_revision}`}
                        sheet={sheet}
                    />
                ))}

                {sheets.data.length === 0 && (
                    <p className="rounded-xl border bg-white p-6">
                        No PPMPs awaiting final approval for this year.
                    </p>
                )}

                <nav
                    aria-label="Approval queue pages"
                    className="flex items-center gap-4"
                >
                    {sheets.prev_page_url && (
                        <Link
                            className={secondary}
                            href={sheets.prev_page_url}
                        >
                            Previous
                        </Link>
                    )}

                    <span className="text-sm">
                        Page {sheets.current_page} of {sheets.last_page}
                    </span>

                    {sheets.next_page_url && (
                        <Link
                            className={secondary}
                            href={sheets.next_page_url}
                        >
                            Next
                        </Link>
                    )}
                </nav>
            </div>
        </AppLayout>
    );
}