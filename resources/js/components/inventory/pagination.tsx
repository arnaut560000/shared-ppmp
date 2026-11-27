import { Link } from '@inertiajs/react';

export type Paginated<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export default function InventoryPagination({ results }: { results: Paginated<unknown> }) {
    if (results.last_page <= 1) return null;

    return (
        <nav aria-label="Pagination" className="flex flex-wrap items-center gap-4 text-sm">
            {results.prev_page_url && <Link href={results.prev_page_url} className="text-blue-700 underline">Previous</Link>}
            <span>Page {results.current_page} of {results.last_page}</span>
            {results.next_page_url && <Link href={results.next_page_url} className="text-blue-700 underline">Next</Link>}
        </nav>
    );
}
