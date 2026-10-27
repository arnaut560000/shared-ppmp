import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Office = {
    id: number;
    code: string;
    name: string;
};

type Account = {
    id: number;
    name: string;
    email: string;
    role: string;
    office_id: number | null;
};

type Props = {
    offices: Office[];
    users: Account[];
    roles: Record<string, string>;
    message: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Administration', href: '/administration' },
];

const fieldClass =
    'mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-blue-500';

const buttonClass =
    'rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50';

function UserAccessForm({
    account,
    offices,
    roles,
}: {
    account: Account;
    offices: Office[];
    roles: Record<string, string>;
}) {
    const form = useForm({
        role: account.role,
        office_id: account.office_id === null ? '' : String(account.office_id),
    });

    return (
        <form
            className="mt-5 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();

                form.patch(`/administration/users/${account.id}`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="rounded-lg bg-muted p-3">
                <p className="font-medium">{account.name}</p>
                <p className="break-all text-sm text-muted-foreground">
                    {account.email}
                </p>
            </div>

            <div>
                <label htmlFor="user-role" className="text-sm font-medium">
                    Role
                </label>
                <select
                    id="user-role"
                    className={fieldClass}
                    value={form.data.role}
                    onChange={(event) => form.setData('role', event.target.value)}
                >
                    {Object.entries(roles).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.role} className="mt-1" />
            </div>

            <div>
                <label htmlFor="user-office" className="text-sm font-medium">
                    Assigned office
                </label>
                <select
                    id="user-office"
                    className={fieldClass}
                    value={form.data.office_id}
                    required={form.data.role === 'office'}
                    onChange={(event) =>
                        form.setData('office_id', event.target.value)
                    }
                >
                    <option value="">No office selected</option>
                    {offices.map((office) => (
                        <option key={office.id} value={office.id}>
                            {office.code} — {office.name}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.office_id} className="mt-1" />
                <p className="mt-2 text-xs text-muted-foreground">
                    An office is required for Department / Office accounts.
                </p>
            </div>

            <button
                type="submit"
                className={buttonClass}
                disabled={form.processing}
            >
                {form.processing ? 'Saving...' : 'Save user access'}
            </button>

            {form.recentlySuccessful && (
                <p role="status" className="text-sm text-blue-700">
                    User access saved.
                </p>
            )}
        </form>
    );
}

export default function Administration({
    offices,
    users,
    roles,
    message,
}: Props) {
    const officeForm = useForm({
        code: '',
        name: '',
    });

    const [selectedId, setSelectedId] = useState('');
    const selectedUser = users.find((user) => String(user.id) === selectedId);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Administration" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <p className="text-sm font-semibold text-blue-700">
                        DIGITALavera ePPRISM
                    </p>
                    <h1 className="mt-1 text-2xl font-bold">Administration</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Manage offices and assign access to registered users.
                    </p>
                </header>

                {message && (
                    <div
                        role="status"
                        className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900"
                    >
                        {message}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-xl border border-t-4 border-t-blue-600 bg-card p-5 shadow-sm">
                        <h2 className="text-lg font-semibold">Create an office</h2>

                        <form
                            className="mt-5 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();

                                officeForm.post('/administration/offices', {
                                    preserveScroll: true,
                                    onSuccess: () => officeForm.reset(),
                                });
                            }}
                        >
                            <div>
                                <label
                                    htmlFor="office-code"
                                    className="text-sm font-medium"
                                >
                                    Office code
                                </label>
                                <input
                                    id="office-code"
                                    className={fieldClass}
                                    placeholder="Example: ICT"
                                    value={officeForm.data.code}
                                    maxLength={20}
                                    required
                                    onChange={(event) =>
                                        officeForm.setData(
                                            'code',
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                />
                                <InputError
                                    message={officeForm.errors.code}
                                    className="mt-1"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="office-name"
                                    className="text-sm font-medium"
                                >
                                    Office name
                                </label>
                                <input
                                    id="office-name"
                                    className={fieldClass}
                                    placeholder="Example: Information and Communications Technology"
                                    value={officeForm.data.name}
                                    maxLength={255}
                                    required
                                    onChange={(event) =>
                                        officeForm.setData('name', event.target.value)
                                    }
                                />
                                <InputError
                                    message={officeForm.errors.name}
                                    className="mt-1"
                                />
                            </div>

                            <button
                                type="submit"
                                className={buttonClass}
                                disabled={officeForm.processing}
                            >
                                {officeForm.processing ? 'Creating...' : 'Create office'}
                            </button>
                        </form>
                    </section>

                    <section className="rounded-xl border border-t-4 border-t-yellow-400 bg-card p-5 shadow-sm">
                        <h2 className="text-lg font-semibold">Assign user access</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Select an existing account to set its role and office.
                        </p>

                        <div className="mt-5">
                            <label
                                htmlFor="selected-user"
                                className="text-sm font-medium"
                            >
                                Registered user
                            </label>
                            <select
                                id="selected-user"
                                className={fieldClass}
                                value={selectedId}
                                onChange={(event) => setSelectedId(event.target.value)}
                            >
                                <option value="">Select a user</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name} — {user.email}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {selectedUser && (
                            <UserAccessForm
                                key={`${selectedUser.id}-${selectedUser.role}-${selectedUser.office_id}`}
                                account={selectedUser}
                                offices={offices}
                                roles={roles}
                            />
                        )}
                    </section>
                </div>

                <section className="overflow-hidden rounded-xl border border-t-4 border-t-red-500 bg-card shadow-sm">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">Registered offices</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {offices.length} office(s)
                        </p>
                    </div>

                    {offices.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            No offices yet. Create the first office using the form above.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-blue-50 text-blue-900">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">Code</th>
                                        <th scope="col" className="px-5 py-3">Office name</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {offices.map((office) => (
                                        <tr key={office.id} className="border-t">
                                            <td className="px-5 py-4 font-semibold">
                                                {office.code}
                                            </td>
                                            <td className="px-5 py-4">{office.name}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}