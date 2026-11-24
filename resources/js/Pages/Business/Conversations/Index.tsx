import { Head, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';

type Conversation = {
    id: string;
    customer_name: string | null;
    whatsapp_wa_id: string;
    status: string;
    automation_state: string;
    assigned_user: string | null;
    last_message: string;
    last_message_at: string | null;
};
type Props = {
    conversations: Conversation[];
    filters: { state: string; status: string; search: string };
    current_user_id: number;
};

export default function ConversationInbox() {
    const { conversations, filters } = usePage<Props>().props;
    const [search, setSearch] = useState(filters.search);
    const [state, setState] = useState(filters.state);
    const [status, setStatus] = useState(filters.status);

    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['conversations'], preserveState: true, preserveScroll: true });
            }
        };
        const interval = window.setInterval(refresh, 15000);
        return () => window.clearInterval(interval);
    }, []);

    function applyFilters(event: FormEvent) {
        event.preventDefault();
        router.get('/conversations', { search, state, status }, { preserveState: true, replace: true });
    }

    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Conversation inbox" />
            <div className="mx-auto max-w-6xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <div className="mt-4 flex flex-wrap items-end justify-between gap-3">
                    <div><h1 className="text-3xl font-bold text-slate-900">Conversation inbox</h1><p className="mt-2 text-slate-600">Review customer messages, take over conversations and control automation.</p></div>
                    <div className="flex flex-wrap items-center gap-2"><span className="rounded-full bg-slate-200 px-3 py-1 text-sm text-slate-700">{conversations.length} conversations shown</span><span className="text-xs text-slate-500">Auto-refresh · 15 sec</span></div>
                </div>
                <form onSubmit={applyFilters} className="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4">
                    <input aria-label="Search customers" className="rounded-lg border border-slate-300 px-3 py-2 text-slate-900 sm:col-span-2" placeholder="Search name or WhatsApp number" value={search} onChange={event => setSearch(event.target.value)} />
                    <select aria-label="Automation state" className="rounded-lg border border-slate-300 px-3 py-2 text-slate-900" value={state} onChange={event => setState(event.target.value)}>
                        <option value="all">All automation states</option><option value="AUTO_ACTIVE">Automation active</option><option value="WAITING_FOR_STAFF">Waiting for staff</option><option value="HUMAN_ACTIVE">Human handling</option>
                    </select>
                    <div className="flex gap-2"><select aria-label="Conversation status" className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-slate-900" value={status} onChange={event => setStatus(event.target.value)}><option value="open">Open</option><option value="closed">Closed</option><option value="all">All</option></select><button className="rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white">Filter</button></div>
                </form>
                <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    {conversations.length === 0 ? <p className="p-8 text-center text-slate-500">No conversations match these filters.</p> : <ul className="divide-y divide-slate-100">
                        {conversations.map(item => <li key={item.id}>
                            <a href={'/conversations/' + item.id} className="block p-5 transition hover:bg-slate-50">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div><p className="font-semibold text-slate-900">{item.customer_name || item.whatsapp_wa_id}</p><p className="text-sm text-slate-500">{item.whatsapp_wa_id}</p></div>
                                    <div className="flex flex-wrap gap-2 text-xs"><span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{item.status}</span><span className={'rounded-full px-2.5 py-1 ' + (item.automation_state === 'WAITING_FOR_STAFF' ? 'bg-amber-100 text-amber-800' : item.automation_state === 'HUMAN_ACTIVE' ? 'bg-blue-100 text-blue-800' : 'bg-emerald-100 text-emerald-800')}>{item.automation_state.replaceAll('_', ' ')}</span></div>
                                </div>
                                <p className="mt-3 line-clamp-2 text-sm text-slate-700">{item.last_message}</p>
                                <p className="mt-2 text-xs text-slate-500">{item.assigned_user ? 'Assigned to ' + item.assigned_user : 'Unassigned'}{item.last_message_at ? ' · ' + new Date(item.last_message_at).toLocaleString() : ''}</p>
                            </a>
                        </li>)}
                    </ul>}
                </section>
            </div>
        </main>
    );
}
