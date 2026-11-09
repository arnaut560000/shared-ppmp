import { planningLink } from '@/lib/planning-navigation';
import { Link } from '@inertiajs/react';

type Props = { workspace: string; summary: string | null; current: 'info' | 'ppas' | 'review' };

export default function PlanningStages({ workspace, summary, current }: Props) {
    const stages = [
        { key: 'info', label: 'AIP details', href: planningLink(workspace, 'info') },
        { key: 'ppas', label: 'PPAs and PPMP', href: planningLink(workspace, 'ppas') },
        { key: 'review', label: 'Review AIP', href: summary },
    ];
    return (
        <nav aria-label="Annual planning steps" className="rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
            <ol className="grid gap-1 sm:grid-cols-3">
                {stages.map(({ key, label, href }, i) => {
                    const active = current === key;
                    const content = (
                        <>
                            <span
                                aria-hidden="true"
                                className={
                                    'grid size-6 shrink-0 place-items-center rounded-full text-xs ' +
                                    (active ? 'bg-blue-700 text-white' : 'bg-slate-200 text-slate-700')
                                }
                            >
                                {i + 1}
                            </span>
                            {label}
                        </>
                    );
                    const style = 'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold ';
                    return (
                        <li key={key}>
                            {href ? (
                                <Link
                                    href={href}
                                    aria-current={active ? 'step' : undefined}
                                    className={style + (active ? 'bg-blue-50 text-blue-900' : 'text-slate-700 hover:bg-slate-50')}
                                >
                                    {content}
                                </Link>
                            ) : (
                                <span aria-disabled="true" title="Create the annual AIP to review it" className={style + 'text-slate-500'}>
                                    {content}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
