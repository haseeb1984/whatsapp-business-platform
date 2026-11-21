import { Head, Link, useForm } from '@inertiajs/react';

type Props = { token: string; email: string };

export default function ResetPassword({ token, email }: Props) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/reset-password');
    }

    return (
        <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <Head title="Choose a new password" />
            <h1 className="text-2xl font-semibold">Choose a new password</h1>
            <form onSubmit={submit} className="mt-8 space-y-4">
                <label className="block text-sm font-medium">Email
                    <input type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                </label>
                <label className="block text-sm font-medium">New password
                    <input type="password" autoComplete="new-password" value={form.data.password} onChange={e => form.setData('password', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                </label>
                <label className="block text-sm font-medium">Confirm new password
                    <input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={e => form.setData('password_confirmation', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                </label>
                {Object.entries(form.errors).map(([key, error]) => <p key={key} className="text-sm text-red-600">{error}</p>)}
                <button disabled={form.processing} className="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-medium text-white disabled:opacity-50">Update password</button>
            </form>
            <Link href="/login" className="mt-6 text-sm underline">Back to sign in</Link>
        </main>
    );
}
