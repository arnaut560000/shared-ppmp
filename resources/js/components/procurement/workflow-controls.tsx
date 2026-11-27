import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

export type Page<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export const field = 'mt-1 block w-full rounded-lg border border-input bg-background px-3 py-2 text-sm';
export const panel = 'space-y-3 rounded-xl border bg-card p-4 sm:p-5';
export const workTitle = 'text-2xl font-bold';
export const peso = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);

export function YearFilter({ year, path }: { year: number; path: string }) {
    const [value, setValue] = useState(String(year));

    return (
        <form className="flex items-end gap-2" onSubmit={(event) => {
            event.preventDefault();
            router.get(path, { year: value });
        }}>
            <label className="text-sm font-medium">
                Fiscal year
                <input className={field + ' w-24'} type="number" min={2020} max={2100} required
                    value={value} onChange={(event) => setValue(event.target.value)} />
            </label>
            <Button type="submit" variant="outline">Show</Button>
        </form>
    );
}

export function Pager({ page }: { page: Page<unknown> }) {
    if (page.last_page <= 1) return null;

    return (
        <nav aria-label="Pages" className="flex flex-wrap items-center gap-3 text-sm">
            {page.prev_page_url && <Link href={page.prev_page_url} className="text-blue-700 underline">Previous</Link>}
            <span>Page {page.current_page} of {page.last_page}</span>
            {page.next_page_url && <Link href={page.next_page_url} className="text-blue-700 underline">Next</Link>}
        </nav>
    );
}

export function Errors({ errors }: { errors: object }) {
    return <>{Object.entries(errors).map(([name, error]) =>
        typeof error === 'string' ? <InputError key={name} message={error} /> : null
    )}</>;
}

export function Success({ message }: { message: string | null }) {
    return message ? <p role="status" className="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{message}</p> : null;
}
