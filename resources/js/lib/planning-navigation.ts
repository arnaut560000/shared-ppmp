export type PlanningSection = 'info' | 'ppas';

export function planningSection(url: string): PlanningSection | null {
    const section = new URL(url, 'http://localhost').searchParams.get('section');
    return section === 'info' || section === 'ppas' ? section : null;
}

export function planningLink(url: string, section: PlanningSection, year?: number): string {
    const target = new URL(url, 'http://localhost');
    target.searchParams.set('section', section);
    if (year !== undefined) target.searchParams.set('year', String(year));
    return target.pathname + target.search;
}
