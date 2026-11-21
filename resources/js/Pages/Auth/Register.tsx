import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const form = useForm({ name: '', email: '', business_name: '', timezone: 'Asia/Karachi', password: '', password_confirmation: '' });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/register');
    }

    return (
        <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <Head title="Create workspace" />
            <h1 className="text-2xl font-semibold">Create your business workspace</h1>
            <p className="mt-2 text-sm text-slate-600">One account and workspace to get started. You can configure WhatsApp and services later.</p>
            <form onSubmit={submit} className="mt-8 space-y-4">
                {([
                    ['name', 'Your name', 'text'],
                    ['email', 'Work email', 'email'],
                    ['business_name', 'Business name', 'text'],
                    ['timezone', 'Timezone (IANA)', 'text'],
                    ['password', 'Password (at least 12 characters)', 'password'],
                    ['password_confirmation', 'Confirm password', 'password'],
                ] as const).map(([field, label, type]) => (
                    <label key={field} className="block text-sm font-medium">{label}
                        <input type={type} autoComplete={field === 'password_confirmation' ? 'new-password' : field === 'password' ? 'new-password' : field} value={form.data[field]} onChange={e => form.setData(field, e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required />
                        {form.errors[field] && <span className="mt-1 block text-sm text-red-600">{form.errors[field]}</span>}
                    </label>
                ))}
                <button disabled={form.processing} className="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-medium text-white disabled:opacity-50">Create workspace</button>
            </form>
            <p className="mt-6 text-sm text-slate-600">Already registered? <Link href="/login" className="font-medium text-slate-900 underline">Sign in</Link></p>
        </main>
    );
}
