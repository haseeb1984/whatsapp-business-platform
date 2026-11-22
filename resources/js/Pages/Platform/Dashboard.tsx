import { Head, router, usePage } from '@inertiajs/react';

type PageProps = {
    metrics: {
        tenants_total: number;
        tenants_active: number;
        tenants_suspended: number;
        users_total: number;
        whatsapp_connections: number;
        calendar_connections: number;
        queue_pending: number | null;
        queue_failed: number | null;
    };
    tenants: {
        id: string;
        name: string;
        slug: string;
        status: string;
        timezone: string;
        users: number;
        whatsapp_connections: number;
        calendar_connections: number;
        created_at: string | null;
    }[];
    recent_failures: { id: string; queue: string; failed_at: string }[];
};

export default function PlatformDashboard() {
    const { metrics, tenants, recent_failures } = usePage<PageProps>().props;
    const cards = [
        ['Total tenants', metrics.tenants_total],
        ['Active tenants', metrics.tenants_active],
        ['Suspended tenants', metrics.tenants_suspended],
        ['Registered users', metrics.users_total],
        ['Active WhatsApp connections', metrics.whatsapp_connections],
        ['Connected calendars', metrics.calendar_connections],
        ['Queued jobs', metrics.queue_pending ?? 'Not available'],
        ['Failed jobs', metrics.queue_failed ?? 'Not available'],
    ] as const;

    return (
        <>
            <Head title="Platform operations" />
            <main className="min-h-screen bg-slate-950 px-5 py-8 text-slate-100 sm:px-8">
                <div className="mx-auto max-w-7xl">
                    <header className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.22em] text-violet-400">Platform control plane</p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Operations overview</h1>
                            <p className="mt-2 text-sm text-slate-400">Global tenant, integration and queue health · Read-only console</p>
                        </div>
                        <div className="flex gap-2">
                            <button onClick={() => router.get('/workspaces')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Business workspaces</button>
                            <button onClick={() => router.post('/logout')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Sign out</button>
                        </div>
                    </header>

                    <section className="mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {cards.map(([label, value]) => <div key={label} className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                            <p className="text-sm text-slate-400">{label}</p>
                            <p className="mt-2 text-3xl font-bold tabular-nums">{typeof value === 'number' ? value.toLocaleString() : value}</p>
                        </div>)}
                    </section>

                    <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-semibold">Business tenants</h2><span className="text-xs text-slate-500">Most recently created · up to 20</span></div>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500"><tr><th className="py-3 pr-4">Workspace</th><th className="py-3 pr-4">Status</th><th className="py-3 pr-4">Users</th><th className="py-3 pr-4">WhatsApp</th><th className="py-3 pr-4">Calendar</th><th className="py-3">Timezone</th></tr></thead>
                                <tbody className="divide-y divide-slate-800">
                                    {tenants.map(tenant => <tr key={tenant.id}>
                                        <td className="py-3 pr-4"><p className="font-semibold text-slate-200">{tenant.name}</p><p className="mt-1 text-xs text-slate-500">{tenant.slug}</p></td>
                                        <td className="py-3 pr-4"><span className={tenant.status === 'active' ? 'rounded-full bg-emerald-950 px-2 py-1 text-xs text-emerald-300' : 'rounded-full bg-amber-950 px-2 py-1 text-xs text-amber-300'}>{tenant.status}</span></td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.users}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.whatsapp_connections}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.calendar_connections}</td>
                                        <td className="py-3 text-slate-400">{tenant.timezone}</td>
                                    </tr>)}
                                    {tenants.length === 0 && <tr><td colSpan={6} className="py-8 text-center text-slate-500">No business tenants have been created.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-5">
                        <div className="flex items-center justify-between"><h2 className="font-semibold">Recent failed jobs</h2><span className="text-xs text-slate-500">Sensitive exception payloads are intentionally hidden</span></div>
                        {recent_failures.length === 0 ? <p className="mt-4 text-sm text-slate-500">No failed jobs recorded, or the failed-jobs table is not configured.</p> : <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[480px] text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase text-slate-500"><tr><th className="py-3 pr-4">Job ID</th><th className="py-3 pr-4">Queue</th><th className="py-3">Failed at</th></tr></thead><tbody className="divide-y divide-slate-800">{recent_failures.map(job => <tr key={job.id}><td className="py-3 pr-4 font-mono text-xs text-slate-400">{job.id}</td><td className="py-3 pr-4">{job.queue}</td><td className="py-3 text-slate-400">{job.failed_at}</td></tr>)}</tbody></table></div>}
                    </section>
                    <footer className="mt-8 border-t border-slate-800 pt-4 text-xs text-slate-500">Access is restricted to emails listed in PLATFORM_ADMIN_EMAILS. This console is read-only.</footer>
                </div>
            </main>
        </>
    );
}
