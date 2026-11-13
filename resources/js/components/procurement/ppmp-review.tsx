import { sheetStatus, type ReviewAction, type Workspace } from '@/components/procurement/schedule';
import { useForm } from '@inertiajs/react';

const labels = {
    submit_to_gso: 'Submitted to GSO',
    complete_gso_review: 'GSO review completed',
    return_from_gso: 'Returned by GSO',
};
const primary = 'rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white disabled:opacity-50';

export default function PpmpReview({ workspace, sheet, unsaved }: {
    workspace: Workspace;
    sheet: NonNullable<Workspace['selected']>;
    unsaved: boolean;
}) {
    const form = useForm({ comment: '' });
    function send(action: ReviewAction) {
        form.transform(({ comment }) => ({ action, version: sheet.version, comment: comment.trim() || null }));
        form.post(`/aips/${workspace.ppa.id}/ppmp/${sheet.id}/review`, { preserveScroll: true });
    }

    return (
        <section aria-label="PPMP review" className="space-y-3 rounded-xl border bg-white p-4 md:p-5">
            <h2 className="font-semibold">PPMP review <span className="ml-2 text-sm font-normal capitalize">{sheetStatus(sheet.status)}</span></h2>
            {workspace.canSubmitToGso && (
                <div className="space-y-2">
                    <button type="button" className={primary} disabled={unsaved || form.processing} onClick={() => send('submit_to_gso')}>
                        Submit PPMP to GSO
                    </button>
                    {unsaved && <p className="text-sm">Save your draft before submitting it.</p>}
                </div>
            )}
            {workspace.canReviewForGso && (
                <>
                    <label className="block text-sm font-medium">
                        Review comment (required when returning)
                        <textarea maxLength={2000} value={form.data.comment}
                            onChange={(event) => form.setData('comment', event.target.value)}
                            className="mt-1 block w-full rounded-lg border p-2" />
                    </label>
                    <div className="flex flex-wrap gap-3">
                        <button type="button" className={primary} disabled={form.processing} onClick={() => send('complete_gso_review')}>Complete GSO review</button>
                        <button type="button" className="rounded-lg border px-4 py-2 font-semibold disabled:opacity-50"
                            disabled={form.processing || !form.data.comment.trim()} onClick={() => send('return_from_gso')}>Return for revision</button>
                    </div>
                </>
            )}
            {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-700">{error}</p>)}
            {workspace.reviewHistory.length > 0 && (
                <ul className="divide-y border-t text-sm">
                    {workspace.reviewHistory.map((event) => (
                        <li key={event.id} className="py-2">
                            {labels[event.action]} <time className="ml-2 text-slate-600">{event.created_at}</time>
                            {event.comment && <p className="mt-1 whitespace-pre-wrap break-words">{event.comment}</p>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
