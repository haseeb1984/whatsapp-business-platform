import { Head, router, usePage } from '@inertiajs/react';

type PageProps = {
    metrics: {
        tenants_total: number;
        tenants_active: number;
        tenants_suspended: number;
        users_total: number;
        whatsapp_connections: number;
        whatsapp_pending: number;
        whatsapp_inactive: number;
        calendar_connections: number;
        calendar_reauthorization_required: number;
        calendar_expired_tokens: number;
        calendar_missing_refresh_tokens: number;
        messages_total: number;
        outbound_pending: number;
        outbound_sending: number;
        outbound_unknown: number;
        outbound_failed: number;
        conversations_total: number;
        appointments_total: number;
        ai_attempts_last_30_days: number | null;
        ai_tokens_last_30_days: number | null;
        ai_failures_last_24_hours: number | null;
        ai_rate_limits_last_24_hours: number | null;
        ai_quota_blocks_last_24_hours: number | null;
        ai_requests_today: number | null;
        ai_tokens_today: number | null;
        audit_events_total: number | null;
        queue_pending: number | null;
        queue_failed: number | null;
    };
    tenants: {
        data: {
        id: string;
        name: string;
        slug: string;
        status: string;
        timezone: string;
        users: number;
        whatsapp_connections: number;
        calendar_connections: number;
        messages: number;
        conversations: number;
        appointments: number;
        ai_attempts_30d: number;
        ai_tokens_30d: number;
        created_at: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: { q: string; status: string };
    recent_failures: { id: string; queue: string; failed_at: string }[];
    recent_audit_events: { id: string; event: string; subject: string | null; tenant: string; actor: string; ip_address: string | null; created_at: string }[];
};

export default function PlatformDashboard() {
    const { metrics, tenants, filters, recent_failures, recent_audit_events } = usePage<PageProps>().props;
    const exportQuery = new URLSearchParams();
    if (filters.q) exportQuery.set('q', filters.q);
    if (filters.status) exportQuery.set('status', filters.status);
    const exportHref = '/platform/tenants/export' + (exportQuery.toString() ? '?' + exportQuery.toString() : '');
    const cards = [
        ['Total tenants', metrics.tenants_total],
        ['Active tenants', metrics.tenants_active],
        ['Suspended tenants', metrics.tenants_suspended],
        ['Registered users', metrics.users_total],
        ['Active WhatsApp connections', metrics.whatsapp_connections],
        ['WhatsApp pending verification', metrics.whatsapp_pending],
        ['WhatsApp non-active connections', metrics.whatsapp_inactive],
        ['Connected calendars', metrics.calendar_connections],
        ['Calendars needing reauthorization', metrics.calendar_reauthorization_required],
        ['Connected calendars with expired tokens', metrics.calendar_expired_tokens],
        ['Connected calendars missing refresh tokens', metrics.calendar_missing_refresh_tokens],
        ['Messages processed', metrics.messages_total],
        ['Outbound queued', metrics.outbound_pending],
        ['Outbound sending', metrics.outbound_sending],
        ['Outbound unknown', metrics.outbound_unknown],
        ['Outbound failed', metrics.outbound_failed],
        ['Conversations', metrics.conversations_total],
        ['Appointments', metrics.appointments_total],
        ['AI attempts · 30 days', metrics.ai_attempts_last_30_days ?? 'Not available'],
        ['AI tokens · 30 days', metrics.ai_tokens_last_30_days ?? 'Not available'],
        ['AI failures · 24 hours', metrics.ai_failures_last_24_hours ?? 'Not available'],
        ['AI rate limits · 24 hours', metrics.ai_rate_limits_last_24_hours ?? 'Not available'],
        ['AI quota blocks · 24 hours', metrics.ai_quota_blocks_last_24_hours ?? 'Not available'],
        ['Provider requests · today', metrics.ai_requests_today ?? 'Not available'],
        ['AI tokens · today', metrics.ai_tokens_today ?? 'Not available'],
        ['Audit events', metrics.audit_events_total ?? 'Not available'],
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
                        {cards.map(([label, value]) => {
                            const hasUnknownDeliveries = label === 'Outbound unknown' && typeof value === 'number' && value > 0;
                            return <div key={label} className={hasUnknownDeliveries ? 'rounded-2xl border border-amber-700 bg-amber-950/40 p-5' : 'rounded-2xl border border-slate-800 bg-slate-900 p-5'}>
                                <p className={hasUnknownDeliveries ? 'text-sm text-amber-300' : 'text-sm text-slate-400'}>{label}</p>
                                <p className={hasUnknownDeliveries ? 'mt-2 text-3xl font-bold tabular-nums text-amber-200' : 'mt-2 text-3xl font-bold tabular-nums'}>{typeof value === 'number' ? value.toLocaleString() : value}</p>
                            </div>;
                        })}
                    </section>

                    <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold">Business tenants</h2><p className="mt-1 text-xs text-slate-500">{tenants.total.toLocaleString()} total workspaces</p></div><a href={exportHref} className="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:bg-slate-800">Export CSV</a></div>
                        <form method="get" action="/platform" className="mt-4 flex flex-wrap gap-2"><input name="q" defaultValue={filters.q} placeholder="Search workspace name or slug" className="min-w-56 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600"/><select name="status" defaultValue={filters.status} className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select><button type="submit" className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">Filter</button><a href="/platform" className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Reset</a></form>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[1280px] text-left text-sm">
                                <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500"><tr><th className="py-3 pr-4">Workspace</th><th className="py-3 pr-4">Status</th><th className="py-3 pr-4">Users</th><th className="py-3 pr-4">WhatsApp</th><th className="py-3 pr-4">Calendar</th><th className="py-3 pr-4">Messages</th><th className="py-3 pr-4">Conversations</th><th className="py-3 pr-4">Appointments</th><th className="py-3 pr-4">AI attempts · 30d</th><th className="py-3 pr-4">AI tokens · 30d</th><th className="py-3">Timezone</th></tr></thead>
                                <tbody className="divide-y divide-slate-800">
                                    {tenants.data.map(tenant => <tr key={tenant.id}>
                                        <td className="py-3 pr-4"><p className="font-semibold text-slate-200">{tenant.name}</p><p className="mt-1 text-xs text-slate-500">{tenant.slug}</p></td>
                                        <td className="py-3 pr-4"><span className={tenant.status === 'active' ? 'rounded-full bg-emerald-950 px-2 py-1 text-xs text-emerald-300' : 'rounded-full bg-amber-950 px-2 py-1 text-xs text-amber-300'}>{tenant.status}</span></td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.users}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.whatsapp_connections}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.calendar_connections}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.messages.toLocaleString()}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.conversations.toLocaleString()}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.appointments.toLocaleString()}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.ai_attempts_30d.toLocaleString()}</td>
                                        <td className="py-3 pr-4 tabular-nums">{tenant.ai_tokens_30d.toLocaleString()}</td>
                                        <td className="py-3 text-slate-400">{tenant.timezone}</td>
                                    </tr>)}
                                    {tenants.data.length === 0 && <tr><td colSpan={11} className="py-8 text-center text-slate-500">No business tenants have been created.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-800 pt-4"><p className="text-xs text-slate-500">Page {tenants.current_page} of {tenants.last_page}</p><nav className="flex flex-wrap gap-1">{tenants.links.map((link, index) => <a key={index} href={link.url || '#'} aria-disabled={!link.url} className={link.active ? 'rounded-md bg-violet-600 px-3 py-2 text-xs font-semibold text-white' : link.url ? 'rounded-md border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:bg-slate-800' : 'pointer-events-none rounded-md border border-slate-800 px-3 py-2 text-xs text-slate-600'}>{link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}</a>)}</nav></div>
                    </section>

                    <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-5">
                        <div className="flex items-center justify-between"><h2 className="font-semibold">Recent failed jobs</h2><span className="text-xs text-slate-500">Sensitive exception payloads are intentionally hidden</span></div>
                        {recent_failures.length === 0 ? <p className="mt-4 text-sm text-slate-500">No failed jobs recorded, or the failed-jobs table is not configured.</p> : <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[480px] text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase text-slate-500"><tr><th className="py-3 pr-4">Job ID</th><th className="py-3 pr-4">Queue</th><th className="py-3">Failed at</th></tr></thead><tbody className="divide-y divide-slate-800">{recent_failures.map(job => <tr key={job.id}><td className="py-3 pr-4 font-mono text-xs text-slate-400">{job.id}</td><td className="py-3 pr-4">{job.queue}</td><td className="py-3 text-slate-400">{job.failed_at}</td></tr>)}</tbody></table></div>}
                    </section>

                    <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-5">
                        <div className="flex items-center justify-between"><h2 className="font-semibold">Recent admin audit history</h2><span className="text-xs text-slate-500">Mutation metadata only · no request payloads</span></div>
                        {recent_audit_events.length === 0 ? <p className="mt-4 text-sm text-slate-500">No admin mutations have been recorded yet.</p> : <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase text-slate-500"><tr><th className="py-3 pr-4">Action</th><th className="py-3 pr-4">Workspace</th><th className="py-3 pr-4">Actor</th><th className="py-3 pr-4">Subject</th><th className="py-3">Time</th></tr></thead><tbody className="divide-y divide-slate-800">{recent_audit_events.map(event => <tr key={event.id}><td className="py-3 pr-4 font-mono text-xs text-slate-300">{event.event}</td><td className="py-3 pr-4">{event.tenant}</td><td className="py-3 pr-4 text-slate-300">{event.actor}</td><td className="py-3 pr-4 text-slate-400">{event.subject || '—'}</td><td className="py-3 text-slate-400">{event.created_at}</td></tr>)}</tbody></table></div>}
                    </section>
                    <footer className="mt-8 border-t border-slate-800 pt-4 text-xs text-slate-500">Access is restricted to emails listed in PLATFORM_ADMIN_EMAILS. This console is read-only.</footer>
                </div>
            </main>
        </>
    );
}
