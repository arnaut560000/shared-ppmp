import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';

type Annual = {
    id: number;
    revision: number;
    review_status: 'draft' | 'submitted' | 'returned' | 'reviewed' | 'attested';
    planning_review: string;
    budget_review: string;
};

type Activity = { id: number; reference: string; title: string };
type Entry = { id: number; action: string; actor: string; date: string; comment: string | null; activity: string | null };
export type ReviewProps = { document: Annual; summaryToken: string; history: Entry[] };

const labels = {
    draft: 'Draft', submitted: 'Under review', returned: 'Returned for revision',
    reviewed: 'Ready for attestation', attested: 'Attested',
};
const button = 'rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50';

export default function AnnualReviewPanel({ document, activities, summaryToken, history }: ReviewProps & { activities: Activity[] }) {
    const role = usePage<SharedData>().props.auth.user.role;
    const form = useForm({ comment: '', activity_id: '' });
    const state = document.review_status;
    const reviewer = role === 'mpdc' || role === 'budget';
    const pending = (role === 'mpdc' ? document.planning_review : document.budget_review) === 'pending';
    function send(action: 'submit' | 'complete' | 'return' | 'attest') {
        form.transform((data) => ({
            action, revision: document.revision, summaryToken,
            comment: action === 'return' ? data.comment : null,
            activity_id: action === 'return' && data.activity_id ? Number(data.activity_id) : null,
        }));
        form.post('/aips/documents/' + document.id + '/review', { preserveScroll: true });
    }

    return (
        <section aria-label="Annual AIP review" className="space-y-3 rounded-xl border bg-white p-5 shadow-sm">
            <h2 className="font-semibold">Annual AIP review · {labels[state]}</h2>
            {state !== 'draft' && <p className="text-sm capitalize">Planning review: {document.planning_review} · Budget review: {document.budget_review}</p>}
            {['submitted', 'reviewed', 'attested'].includes(state) && <p className="text-sm">The figures below are from the submitted AIP.</p>}
            {role === 'office' && (state === 'draft' || state === 'returned') && (
                <button className={button} disabled={form.processing} onClick={() => send('submit')}>
                    {state === 'returned' ? 'Resubmit AIP for Review' : 'Submit AIP for Review'}
                </button>
            )}
            {reviewer && state === 'submitted' && pending && (
                <div className="space-y-3">
                    <label className="block text-sm">Activity to revise
                        <select className="mt-1 block w-full rounded border p-2" value={form.data.activity_id}
                            onChange={(e) => form.setData('activity_id', e.target.value)}>
                            <option value="">Whole annual AIP</option>
                            {activities.map((a) => <option key={a.id} value={a.id}>{a.reference} · {a.title}</option>)}
                        </select>
                    </label>
                    <label className="block text-sm">Reason for returning the AIP
                        <textarea className="mt-1 block w-full rounded border p-2" maxLength={2000} value={form.data.comment}
                            onChange={(e) => form.setData('comment', e.target.value)} />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <button className={button} disabled={form.processing} onClick={() => send('complete')}>
                            {role === 'mpdc' ? 'Complete planning review' : 'Complete budget review'}
                        </button>
                        <button className="rounded-lg border px-4 py-2 text-sm disabled:opacity-50"
                            disabled={form.processing || !form.data.comment.trim()} onClick={() => send('return')}>Return for revision</button>
                    </div>
                </div>
            )}
            {role === 'mayor' && state === 'reviewed' && (
                <button className={button} disabled={form.processing} onClick={() => send('attest')}>Attest AIP</button>
            )}
            {Object.values(form.errors).map((error) => <p key={error} role="alert" className="text-sm text-red-700">{error}</p>)}
            {history.length > 0 && (
                <details className="border-t pt-2" open={state === 'returned'}>
                    <summary className="cursor-pointer text-sm font-semibold">Annual AIP review history</summary>
                    <ol className="divide-y text-sm">
                        {history.map((event) => <li key={event.id} className="py-2">
                            <strong>{event.action}</strong> · {event.actor} · {event.date}
                            {event.activity && <p>{event.activity}</p>}
                            {event.comment && <p className="whitespace-pre-wrap">{event.comment}</p>}
                        </li>)}
                    </ol>
                </details>
            )}
        </section>
    );
}
