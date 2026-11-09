import { type Expense, money } from './schedule';

export default function AllocationSummary({ available, used }: { available: Record<Expense, number>; used: Record<Expense, number> }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-left text-sm">
                <thead className="bg-blue-50 text-blue-950">
                    <tr>
                        {['Expense class', 'Available before this list', 'This list', 'Remaining'].map((h) => (
                            <th className="p-3" key={h}>
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {(['MOOE', 'CO'] as const).map((k) => (
                        <tr key={k} className="border-t">
                            <th className="p-3">{k}</th>
                            <td className="p-3">{money(available[k])}</td>
                            <td className="p-3">{money(used[k])}</td>
                            <td className={used[k] > available[k] ? 'p-3 font-semibold text-red-700' : 'p-3'}>{money(available[k] - used[k])}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
