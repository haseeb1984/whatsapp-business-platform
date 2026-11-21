import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Message = { id: string; direction: string; message_type: string; body: string | null; delivery_status: string; sent_at: string | null };
type Note = { id: string; body: string; author: string; created_at: string | null };
type TeamMember = { id: number; name: string; email: string };
type Conversation = {
    id: string; customer_name: string | null; whatsapp_wa_id: string; status: string; automation_state: string;
    assigned_user_id: number | null; assigned_user_name: string | null; handover_requested_at: string | null; handover_reason: string | null;
};
type Props = {
    conversation: Conversation; messages: Message[]; notes: Note[]; team_members: TeamMember[];
    current_user_id: number; current_user_role: string;
};

export default function ConversationShow() {
    const { conversation, messages, notes, team_members, current_user_id, current_user_role } = usePage<Props>().props;
    const reply = useForm({ body: '' });
    const note = useForm({ body: '' });
    const assignment = useForm({ assigned_user_id: conversation.assigned_user_id ? String(conversation.assigned_user_id) : '' });
    const isOwner = conversation.assigned_user_id === current_user_id;
    const isAdmin = current_user_role === 'business_admin';
    const humanActive = conversation.automation_state === 'HUMAN_ACTIVE' && isOwner;
    const canTake = conversation.status === 'open'
        && conversation.automation_state !== 'HUMAN_ACTIVE'
        && (!conversation.assigned_user_id || isAdmin || isOwner);

    function submitReply(event: FormEvent) {
        event.preventDefault();
        reply.post('/conversations/' + conversation.id + '/reply', { onSuccess: () => reply.reset() });
    }
    function submitNote(event: FormEvent) {
        event.preventDefault();
        note.post('/conversations/' + conversation.id + '/notes', { onSuccess: () => note.reset() });
    }
    function assign(event: FormEvent) {
        event.preventDefault();
        assignment.put('/conversations/' + conversation.id + '/assign');
    }

    const field = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Customer conversation" />
            <div className="mx-auto max-w-5xl">
                <a href="/conversations" className="text-sm text-blue-700 hover:underline">← Conversation inbox</a>
                <div className="mt-4 rounded-xl border border-slate-200 bg-white p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div><h1 className="text-2xl font-bold text-slate-900">{conversation.customer_name || conversation.whatsapp_wa_id}</h1><p className="mt-1 text-sm text-slate-500">{conversation.whatsapp_wa_id}</p><p className="mt-2 text-sm text-slate-600">Assigned to: {conversation.assigned_user_name || 'Nobody'}</p></div>
                        <div className="flex flex-wrap gap-2"><span className="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700">{conversation.status}</span><span className="rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-800">{conversation.automation_state.replaceAll('_', ' ')}</span></div>
                    </div>
                    {conversation.handover_reason && <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Handover reason: {conversation.handover_reason}</p>}
                    <div className="mt-5 flex flex-wrap gap-2">
                        {canTake && <button onClick={() => router.post('/conversations/' + conversation.id + '/take')} className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Take over conversation</button>}
                        {conversation.status === 'open' && humanActive && <button onClick={() => router.post('/conversations/' + conversation.id + '/release')} className="rounded-lg border border-emerald-300 px-4 py-2 text-sm font-semibold text-emerald-800">Release to automation</button>}
                        {conversation.status === 'open' && conversation.automation_state === 'AUTO_ACTIVE' && <button onClick={() => router.post('/conversations/' + conversation.id + '/handover', { reason: 'Requested by team member' })} className="rounded-lg border border-amber-300 px-4 py-2 text-sm font-semibold text-amber-800">Pause automation / request staff</button>}
                        {isAdmin && conversation.status === 'open' && <button onClick={() => router.post('/conversations/' + conversation.id + '/close')} className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700">Close conversation</button>}
                        {isAdmin && conversation.status === 'closed' && <button onClick={() => router.post('/conversations/' + conversation.id + '/reopen')} className="rounded-lg border border-blue-300 px-4 py-2 text-sm text-blue-800">Reopen for staff</button>}
                    </div>
                    {isAdmin && conversation.status === 'open' && <form onSubmit={assign} className="mt-5 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4"><label className="min-w-64 flex-1 text-sm font-medium text-slate-700">Assign to workspace user<select className={field} value={assignment.data.assigned_user_id} onChange={event => assignment.setData('assigned_user_id', event.target.value)} required><option value="">Choose team member</option>{team_members.map(member => <option key={member.id} value={member.id}>{member.name} · {member.email}</option>)}</select></label><button disabled={assignment.processing} className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700">Assign</button></form>}
                </div>
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Message history</h2>
                    <div className="mt-4 space-y-3">
                        {messages.map(message => <div key={message.id} className={'max-w-3xl rounded-xl p-4 ' + (message.direction === 'outbound' ? 'ml-auto bg-blue-50' : 'bg-slate-100')}>
                            <div className="flex items-center justify-between gap-3 text-xs text-slate-500"><span>{message.direction === 'outbound' ? 'Business' : 'Customer'} · {message.message_type}</span><span>{message.delivery_status}</span></div>
                            <p className="mt-2 whitespace-pre-wrap text-sm text-slate-800">{message.body || '[' + message.message_type + ' message — content preview unavailable]'}</p>
                            {message.sent_at && <p className="mt-2 text-right text-xs text-slate-400">{new Date(message.sent_at).toLocaleString()}</p>}
                        </div>)}
                        {messages.length === 0 && <p className="text-sm text-slate-500">No messages yet.</p>}
                    </div>
                </section>
                {humanActive && conversation.status === 'open' && <div className="mt-6 grid gap-6 md:grid-cols-2">
                    <form onSubmit={submitReply} className="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold text-slate-900">Reply to customer</h2>
                        <textarea className={field} rows={4} maxLength={4096} value={reply.data.body} onChange={event => reply.setData('body', event.target.value)} placeholder="Write a WhatsApp reply..." required />
                        {reply.errors.body && <p className="mt-1 text-sm text-red-600">{reply.errors.body}</p>}
                        <button disabled={reply.processing} className="mt-3 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Send reply</button>
                    </form>
                    <form onSubmit={submitNote} className="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold text-slate-900">Internal note</h2>
                        <textarea className={field} rows={4} maxLength={5000} value={note.data.body} onChange={event => note.setData('body', event.target.value)} placeholder="Private note for your team..." required />
                        {note.errors.body && <p className="mt-1 text-sm text-red-600">{note.errors.body}</p>}
                        <button disabled={note.processing} className="mt-3 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Save note</button>
                    </form>
                </div>}
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Internal notes</h2>
                    {notes.length === 0 ? <p className="mt-3 text-sm text-slate-500">No internal notes.</p> : <ul className="mt-3 space-y-3">{notes.map(item => <li key={item.id} className="rounded-lg bg-yellow-50 p-4"><p className="whitespace-pre-wrap text-sm text-slate-800">{item.body}</p><p className="mt-2 text-xs text-slate-500">{item.author}{item.created_at ? ' · ' + new Date(item.created_at).toLocaleString() : ''}</p></li>)}</ul>}
                </section>
            </div>
        </main>
    );
}
