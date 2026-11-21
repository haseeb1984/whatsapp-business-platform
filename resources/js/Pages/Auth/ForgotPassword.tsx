import { Head, Link, useForm, usePage } from '@inertiajs/react';

type Props = { status?: string | null };

export default function ForgotPassword() {
    const { status } = usePage<Props>().props;
    const form = useForm({ email: '' });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/forgot-password');
    }

    return (
        <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <Head title="Forgot password" />
            <h1 className="text-2xl font-semibold">Reset your password</h1>
            <p className="mt-2 text-sm text-slate-600">Enter your account email and we will send a password reset link if the account exists.</p>
            {status && <p className="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800">{status}</p>}
            <form onSubmit={submit} className="mt-8 space-y-4">
                <label className="block text-sm font-medium">Email
                    <input type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                </label>
                {form.errors.email && <p className="text-sm text-red-600">{form.errors.email}</p>}
                <button disabled={form.processing} className="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-medium text-white disabled:opacity-50">Send reset link</button>
            </form>
            <Link href="/login" className="mt-6 text-sm underline">Back to sign in</Link>
        </main>
    );
}
