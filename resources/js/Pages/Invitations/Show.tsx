import { Head, router, usePage } from '@inertiajs/react';

type Props = { tenantName: string; email: string; role: string; expiresAt: string; token: string };

export default function InvitationShow() {
    const { tenantName, email, role, expiresAt, token } = usePage<Props>().props;

    return (
        <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-6 py-12">
            <Head title="Workspace invitation" />
            <section className="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                <h1 className="text-2xl font-bold text-slate-900">Join {tenantName}</h1>
                <p className="mt-3 text-slate-600">You are invited as <strong>{role.replace('_', ' ')}</strong>.</p>
                <p className="mt-2 text-sm text-slate-600">Invitation email: {email}</p>
                <p className="mt-2 text-sm text-slate-500">Expires: {expiresAt}</p>
                <button onClick={() => router.post('/invitations/' + encodeURIComponent(token) + '/accept')} className="mt-6 rounded-lg bg-blue-700 px-5 py-3 font-semibold text-white">Accept invitation</button>
                <p className="mt-4 text-xs text-slate-500">You must be signed in and verified using the invited email address.</p>
            </section>
        </main>
    );
}
