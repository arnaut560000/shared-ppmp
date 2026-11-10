import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

type Kind = 'aip' | 'ppa' | 'sub-ppa';
type Preview = {
    title: string;
    year: number;
    counts: { ppas: number; sub_ppas: number; ppmps: number; items: number };
    token: string;
    blocked: string | null;
};
const labels = { aip: 'AIP', ppa: 'PPA', 'sub-ppa': 'Sub PPA' };
const button = 'rounded-md border px-3 py-2 text-sm font-semibold';
const danger = button + ' border-red-700 bg-red-700 text-white disabled:opacity-50';

export default function PlanningDeleteButton({ kind, id }: { kind: Kind; id: number }) {
    const [step, setStep] = useState<0 | 1 | 2>(0);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const url = '/aips/deletion/' + kind + '/' + id;
    const open = step !== 0;

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        async function load() {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal, cache: 'no-store' });
                if (!response.ok) throw new Error();
                const data: Preview = await response.json();
                if (!controller.signal.aborted) setPreview(data);
            } catch {
                if (!controller.signal.aborted) setError('Could not load the deletion details. Close the dialog and try again.');
            }
        }
        void load();
        return () => controller.abort();
    }, [open, url]);

    function remove() {
        if (step !== 2 || !preview || preview.blocked || busy || error) return;
        setBusy(true);
        router.delete(url, {
            data: { token: preview.token, confirmed: true },
            preserveState: 'errors',
            onSuccess: () => setStep(0),
            onError: (errors) => setError(errors.workflow ?? 'Close the dialog and review the deletion again.'),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <>
            <button
                type="button"
                className={button + ' inline-flex items-center gap-1.5 border-red-200 bg-white text-red-700 hover:bg-red-50'}
                onClick={() => {
                    setPreview(null);
                    setError('');
                    setStep(1);
                }}
            >
                <Trash2 size={15} /> Delete {labels[kind]}
            </button>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!value && !busy) setStep(0);
                }}
            >
                <DialogContent className="bg-white text-slate-900">
                    <DialogHeader>
                        <DialogTitle>{step === 2 ? 'Confirm permanent deletion' : 'Review deletion'}</DialogTitle>
                        <DialogDescription>
                            {preview ? labels[kind] + ' · FY ' + preview.year + ' · ' + preview.title : 'Loading the selected record…'}
                        </DialogDescription>
                    </DialogHeader>
                    {preview &&
                        !preview.blocked &&
                        (step === 1 ? (
                            <div>
                                <p className="mb-2">The following records will be permanently deleted, including the selected record.</p>
                                <ul className="grid grid-cols-2 gap-2 rounded-md border bg-slate-50 p-3 text-sm">
                                    <li>PPAs: {preview.counts.ppas}</li>
                                    <li>Sub PPAs: {preview.counts.sub_ppas}</li>
                                    <li>PPMP lists: {preview.counts.ppmps}</li>
                                    <li>Item lines: {preview.counts.items}</li>
                                </ul>
                            </div>
                        ) : (
                            <p>
                                Delete <strong>{preview.title}</strong> and the records you just reviewed? This cannot be undone.
                            </p>
                        ))}
                    {(error || preview?.blocked) && (
                        <p role="alert" className="text-sm text-red-700">
                            {error || preview?.blocked}
                        </p>
                    )}
                    <DialogFooter>
                        <button type="button" autoFocus className={button} disabled={busy} onClick={() => setStep(0)}>
                            Cancel
                        </button>
                        {step === 1 && !preview?.blocked && (
                            <button type="button" disabled={!preview || !!error} className={danger} onClick={() => setStep(2)}>
                                Continue
                            </button>
                        )}
                        {step === 2 && (
                            <button type="button" className={danger} disabled={busy || !!error} onClick={remove}>
                                {busy ? 'Deleting…' : 'Delete permanently'}
                            </button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
