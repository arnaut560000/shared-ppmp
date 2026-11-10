import { money, type Workspace } from './schedule';

type Props = {
    budget: Workspace['budget'];
    listCost: number;
    remaining: number | null;
};

export default function AllocationSummary({ budget, listCost, remaining }: Props) {
    return (
        <section aria-label="Office budget" className="rounded-lg border bg-slate-50 p-4 text-sm">
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt>Office ceiling</dt>
                    <dd className="font-semibold">{budget.ceiling_cents === null ? 'Not assigned' : money(budget.ceiling_cents)}</dd>
                </div>
                <div>
                    <dt>Saved office spending</dt>
                    <dd className="font-semibold">{money(budget.spent_cents)}</dd>
                </div>
                <div>
                    <dt>This PPMP</dt>
                    <dd className="font-semibold">{money(listCost)}</dd>
                </div>
                <div aria-live="polite">
                    <dt>Office balance after save</dt>
                    <dd className={remaining !== null && remaining < 0 ? 'font-bold text-red-700' : 'font-bold'}>
                        {remaining === null ? 'Not assigned' : money(remaining)}
                    </dd>
                </div>
            </dl>
            {remaining === null && <p className="mt-3 text-amber-900">An office budget is required to save procurement items.</p>}
            {remaining !== null && remaining < 0 && <p className="mt-3 text-red-700">This list exceeds the office budget by {money(-remaining)}.</p>}
        </section>
    );
}
