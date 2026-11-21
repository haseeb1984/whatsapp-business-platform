import { Head, router, usePage } from '@inertiajs/react';

type Props = { status?: string | null };

export default function VerifyEmail() {
    const { status } = usePage<Props>().props;

    return (
        <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <Head title="Verify your email" />
            <h1 className="text-2xl font-semibold">Verify your email address</h1>
            <p className="mt-3 text-sm leading-6 text-slate-600">We sent a verification link to your email address. Open that link to activate access to your business workspace.</p>
            {status && <p className="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800">{status}</p>}
            <button onClick={() => router.post('/email/verification-notification')} className="mt-8 w-full rounded-lg bg-slate-900 px-4 py-2.5 font-medium text-white">Resend verification email</button>
            <button onClick={() => router.post('/logout')} className="mt-4 text-sm text-slate-600 underline">Sign out</button>
        </main>
    );
}
