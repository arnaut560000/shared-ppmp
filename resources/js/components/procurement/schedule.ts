export const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export type Expense = 'MOOE' | 'CO';
export type Item = {
    id: number;
    code: string;
    name: string;
    specification: string | null;
    category?: string;
    unit: string;
    expense_class: Expense;
    price_cents: number;
};
export type Line = {
    catalogue_item_id: number;
    expected_price_cents: number;
    monthly_quantities: number[];
};
export type SavedItem = Omit<Item, 'id'> & {
    catalogue_item_id: number;
    monthly_quantities: number[] | null;
    quarterly_quantities: number[] | null;
};
export type Sheet = { id: number; reference: string; status: string; total_cents: number };
export type Workspace = {
    ppa: { id: number; reference: string; title: string; fiscal_year: number; fund_source: string; status: string };
    office: string;
    backUrl: string;
    summaryUrl: string;
    sheets: Sheet[];
    reference: string;
    selected: { id: number; reference: string; status: string; version: number; items: SavedItem[] } | null;
    catalogue: Item[];
    available: Record<Expense, number>;
    canPrepare: boolean;
    canEdit: boolean;
    legacy: boolean;
    message: string | null;
};
const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
export const money = (cents: number) => pesos.format(cents / 100);
export const sum = (values: number[]) => values.reduce((total, value) => total + value, 0);
export const quarters = (values: number[]) => [0, 1, 2, 3].map((q) => sum(values.slice(q * 3, q * 3 + 3)));
