import { Head, Link, useForm, usePage } from '@inertiajs/react';
type Props = { status?: string | null };

export default function Login() {
    const { status } = usePage<Props>().props;
    const form = useForm({ email: '', password: '', remember: false });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login');
    }

    return (
        <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <Head title="Sign in" />
            <h1 className="text-2xl font-semibold">Sign in to your workspace</h1>
            <p className="mt-2 text-sm text-slate-600">Manage your WhatsApp business conversations and automation.</p>
            {status && <p className="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800">{status}</p>}
            <form onSubmit={submit} className="mt-8 space-y-5">
                <label className="block text-sm font-medium">Email
                    <input type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                </label>
                {form.errors.email && <p className="text-sm text-red-600">{form.errors.email}</p>}
                <label className="block text-sm font-medium">Password
                    <input type="password" autoComplete="current-password" value={form.data.password} onChange={e => form.setData('password', e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                </label>
                <div className="flex items-center justify-between"><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remember} onChange={e => form.setData('remember', e.target.checked)} /> Remember me</label><Link href="/forgot-password" className="text-sm underline">Forgot password?</Link></div>
                <button disabled={form.processing} className="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-medium text-white disabled:opacity-50">Sign in</button>
            </form>
            <p className="mt-6 text-sm text-slate-600">New here? <Link href="/register" className="font-medium text-slate-900 underline">Create a workspace</Link></p>
        </main>
    );
}
