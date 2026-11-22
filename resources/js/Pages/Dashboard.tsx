import { Head, router, usePage } from '@inertiajs/react';

type PageProps = {
    tenant: { id: string; name: string; timezone: string };
    current_user_role: 'business_admin' | 'staff';
    metrics: {
        customers: number;
        open_conversations: number;
        waiting_for_staff: number;
        upcoming_appointments: number;
        active_services: number;
        active_staff: number;
        pending_reminders: number;
    };
    integrations: { whatsapp: boolean; google_calendar: boolean };
    recent_conversations: {
        id: string;
        customer: string | null;
        automation_state: string;
        last_message: string;
        last_message_at: string | null;
    }[];
    upcoming_appointments: {
        id: string;
        customer: string | null;
        service: string;
        staff: string | null;
        starts_at: string;
        starts_at_label: string;
    }[];
};

const adminActions = [
    ['Business profile', '/business/profile'],
    ['Opening hours', '/business/hours'],
    ['Holidays', '/business/holidays'],
    ['Services & pricing', '/business/services'],
    ['Staff & availability', '/business/staff'],
    ['FAQs & AI answers', '/business/faqs'],
    ['WhatsApp connection', '/business/whatsapp'],
    ['Google Calendar', '/business/calendar'],
];

export default function Dashboard() {
    const { tenant, current_user_role, metrics, integrations, recent_conversations, upcoming_appointments } = usePage<PageProps>().props;
    const isAdmin = current_user_role === 'business_admin';
    const cards = [
        { label: 'Open conversations', value: metrics.open_conversations, detail: metrics.waiting_for_staff + ' waiting for staff', href: '/conversations' },
        { label: 'Upcoming appointments', value: metrics.upcoming_appointments, detail: 'Confirmed bookings', href: '/business/appointments' },
        { label: 'Customers', value: metrics.customers, detail: 'Known WhatsApp customers', href: '/conversations' },
        { label: 'Pending reminders', value: metrics.pending_reminders, detail: 'Scheduled or awaiting send', href: '/business/appointments' },
        ...(isAdmin ? [
            { label: 'Active services', value: metrics.active_services, detail: 'Available for booking', href: '/business/services' },
            { label: 'Active staff', value: metrics.active_staff, detail: 'Available team members', href: '/business/staff' },
        ] : []),
    ];

    return (
        <>
            <Head title="Business dashboard" />
            <main className="min-h-screen bg-slate-950 px-5 py-8 text-slate-100 sm:px-8">
                <div className="mx-auto max-w-6xl">
                    <header className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.22em] text-blue-400">JuncSys · Business workspace</p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">{tenant.name}</h1>
                            <p className="mt-2 text-sm text-slate-400">Operational overview · Business timezone: {tenant.timezone}</p>
                        </div>
                        <div className="flex gap-2">
                            <button onClick={() => router.get('/workspaces')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Switch workspace</button>
                            <button onClick={() => router.post('/logout')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Sign out</button>
                        </div>
                    </header>

                    <section className="mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {cards.map(card => <a key={card.label} href={card.href} className="rounded-2xl border border-slate-800 bg-slate-900 p-5 transition hover:border-slate-600">
                            <p className="text-sm text-slate-400">{card.label}</p>
                            <p className="mt-2 text-3xl font-bold tabular-nums">{card.value.toLocaleString()}</p>
                            <p className="mt-1 text-xs text-slate-500">{card.detail}</p>
                        </a>)}
                    </section>

                    <section className="mt-6 grid gap-4 md:grid-cols-2">
                        <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                            <div className="flex items-center justify-between gap-3"><h2 className="font-semibold">Integrations</h2><span className="text-xs text-slate-500">Current workspace</span></div>
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                <div className="rounded-xl border border-slate-800 bg-slate-950 p-4"><p className="text-sm text-slate-400">WhatsApp Cloud API</p><p className={integrations.whatsapp ? 'mt-2 text-sm font-semibold text-emerald-400' : 'mt-2 text-sm font-semibold text-amber-300'}>{integrations.whatsapp ? 'Connected and active' : 'Not connected'}</p><a href="/business/whatsapp" className="mt-2 inline-block text-xs text-blue-300 hover:underline">Manage connection</a></div>
                                <div className="rounded-xl border border-slate-800 bg-slate-950 p-4"><p className="text-sm text-slate-400">Google Calendar</p><p className={integrations.google_calendar ? 'mt-2 text-sm font-semibold text-emerald-400' : 'mt-2 text-sm font-semibold text-amber-300'}>{integrations.google_calendar ? 'Connected' : 'Not connected'}</p><a href="/business/calendar" className="mt-2 inline-block text-xs text-blue-300 hover:underline">Manage calendar</a></div>
                            </div>
                        </div>
                        <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                            <h2 className="font-semibold">Quick actions</h2>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <a href="/conversations" className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">Open inbox</a>
                                <a href="/business/appointments" className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800">Appointments</a>
                                {isAdmin && adminActions.map(([label, href]) => <a key={href} href={href} className="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">{label}</a>)}
                            </div>
                        </div>
                    </section>

                    <section className="mt-6 grid gap-4 lg:grid-cols-2">
                        <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                            <div className="flex items-center justify-between"><h2 className="font-semibold">Recent conversations</h2><a href="/conversations" className="text-xs text-blue-300 hover:underline">View inbox</a></div>
                            {recent_conversations.length === 0 ? <p className="mt-4 text-sm text-slate-500">No open conversations yet.</p> : <ul className="mt-3 divide-y divide-slate-800">
                                {recent_conversations.map(item => <li key={item.id}><a href={'/conversations/' + item.id} className="block py-3 hover:bg-slate-800/40"><div className="flex items-center justify-between gap-3"><p className="text-sm font-semibold text-slate-200">{item.customer || 'Customer'}</p><span className="text-xs text-slate-500">{item.last_message_at || '—'}</span></div><p className="mt-1 line-clamp-2 text-sm text-slate-400">{item.last_message}</p><p className="mt-1 text-[11px] text-blue-300">{item.automation_state.replaceAll('_', ' ')}</p></a></li>)}
                            </ul>}
                        </div>

                        <div className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                            <div className="flex items-center justify-between"><h2 className="font-semibold">Upcoming appointments</h2><a href="/business/appointments" className="text-xs text-blue-300 hover:underline">View schedule</a></div>
                            {upcoming_appointments.length === 0 ? <p className="mt-4 text-sm text-slate-500">No upcoming confirmed appointments.</p> : <ul className="mt-3 divide-y divide-slate-800">
                                {upcoming_appointments.map(item => <li key={item.id} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-200">{item.customer || 'Customer'}</p><p className="mt-1 truncate text-xs text-slate-400">{item.service} · {item.staff || 'Staff unassigned'}</p></div><span className="whitespace-nowrap rounded-lg bg-slate-950 px-3 py-2 text-xs text-slate-300">{item.starts_at_label}</span></li>)}
                            </ul>}
                        </div>
                    </section>
                    <footer className="mt-8 border-t border-slate-800 pt-4 text-xs text-slate-500">Role: {current_user_role.replace('_', ' ')} · Workspace data is isolated to this business.</footer>
                </div>
            </main>
        </>
    );
}
