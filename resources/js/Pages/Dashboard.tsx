import { Head, router, usePage } from '@inertiajs/react';

type PageProps = { tenant: { id: string; name: string } | null };

const modules = [
    'Business workspaces',
    'WhatsApp conversations',
    'Approved FAQs and AI assistant',
    'Human handover',
    'Appointments and Google Calendar',
];

export default function Dashboard() {
    const { tenant } = usePage<PageProps>().props;
    return (
        <>
            <Head title="Project dashboard" />
            <main className="min-h-screen bg-slate-950 px-6 py-12 text-slate-100">
                <div className="mx-auto max-w-5xl">
                    <div className="flex items-center justify-between gap-4"><p className="text-sm font-semibold uppercase tracking-[0.2em] text-blue-400">JuncSys · SaaS platform</p><div className="flex gap-2"><button onClick={() => router.get('/workspaces')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Switch workspace</button><button onClick={() => router.post('/logout')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Sign out</button></div></div>
                    <div className="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">WhatsApp Business Platform</h1>
                            <p className="mt-3 max-w-2xl text-slate-400">One configurable virtual receptionist for multiple independent businesses.</p><p className="mt-2 text-sm text-blue-200">Current workspace: {tenant?.name ?? 'Not selected'}</p>
                        </div>
                        <span className="inline-flex w-fit rounded-full border border-amber-400/30 bg-amber-400/10 px-3 py-1 text-sm text-amber-200">Foundation stage</span>
                    </div>
                    <div className="mt-8 flex flex-wrap gap-3"><a href="/business/profile" className="inline-flex rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">Configure business profile</a><a href="/business/hours" className="inline-flex rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">Set business hours</a><a href="/business/holidays" className="inline-flex rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">Manage holidays</a><a href="/business/services" className="inline-flex rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">Manage services</a></div>
                    <section className="mt-10 grid gap-4 sm:grid-cols-2">
                        {[
                            ['Backend', 'Laravel 13 · PHP 8.3+'],
                            ['Database', 'Neon · PostgreSQL'],
                            ['Frontend', 'React · TypeScript · Inertia'],
                            ['Deployment target', 'Hostinger Business'],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
                                <p className="text-sm text-slate-400">{label}</p>
                                <p className="mt-2 text-xl font-semibold">{value}</p>
                            </div>
                        ))}
                    </section>
                    <section className="mt-10 rounded-2xl border border-slate-800 bg-slate-900 p-6">
                        <h2 className="text-lg font-semibold">Planned product modules</h2>
                        <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                            {modules.map((module) => <li key={module} className="flex items-center gap-3 text-slate-300"><span className="h-2 w-2 rounded-full bg-blue-400" />{module}</li>)}
                        </ul>
                        <p className="mt-6 border-t border-slate-800 pt-4 text-sm text-slate-500">Business profile and weekly opening hours are implemented as the first configuration modules. Other product modules remain planned.</p>
                    </section>
                </div>
            </main>
        </>
    );
}
