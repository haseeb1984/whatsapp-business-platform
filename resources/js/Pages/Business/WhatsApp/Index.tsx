import FormErrorSummary from '../../../Components/FormErrorSummary';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Connection = {
    id: string;
    phone_number_id: string;
    waba_id: string;
    display_phone_number: string | null;
    verified_name: string | null;
    status: 'pending' | 'active' | 'disabled';
    verified_at: string | null;
};
type Template = { id: string; provider_template_id: string; name: string; language: string; category: string | null; status: string; synced_at: string | null };
type Props = { connections: Connection[]; templates: Template[] };
type ConnectionForm = {
    phone_number_id: string;
    waba_id: string;
    display_phone_number: string;
    verified_name: string;
    access_token: string;
    status: 'pending' | 'disabled';
};
const emptyForm: ConnectionForm = {
    phone_number_id: '',
    waba_id: '',
    display_phone_number: '',
    verified_name: '',
    access_token: '',
    status: 'pending',
};

export default function WhatsAppConnections() {
    const { connections, templates } = usePage<Props>().props;
    const { data, setData, post, put, processing, errors, reset } = useForm<ConnectionForm>(emptyForm);
    const [editingId, setEditingId] = useState<string | null>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => { reset(); setEditingId(null); } };
        if (editingId) put('/business/whatsapp/' + editingId, options);
        else post('/business/whatsapp', options);
    }

    function edit(connection: Connection) {
        setEditingId(connection.id);
        setData({
            phone_number_id: connection.phone_number_id,
            waba_id: connection.waba_id,
            display_phone_number: connection.display_phone_number ?? '',
            verified_name: connection.verified_name ?? '',
            access_token: '',
            status: connection.status === 'disabled' ? 'disabled' : 'pending',
        });
    }

    const field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="WhatsApp connections" />
            <div className="mx-auto max-w-5xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">WhatsApp Cloud API connections</h1>
                <p className="mt-2 text-slate-600">Register a Meta phone number and its permanent access token. Tokens are encrypted at rest and never returned to this page.</p>
                <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Connections remain pending until Meta confirms the phone number and its WABA membership.</p>
                <form onSubmit={submit} className="mt-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-2">
                    <FormErrorSummary errors={errors} />
                    <label className="text-sm font-medium text-slate-700">Phone Number ID<input className={field} value={data.phone_number_id} onChange={e => setData('phone_number_id', e.target.value)} required maxLength={64} />{errors.phone_number_id && <span className="text-red-600">{errors.phone_number_id}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">WhatsApp Business Account ID<input className={field} value={data.waba_id} onChange={e => setData('waba_id', e.target.value)} required maxLength={64} />{errors.waba_id && <span className="text-red-600">{errors.waba_id}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Display phone number<input className={field} value={data.display_phone_number} onChange={e => setData('display_phone_number', e.target.value)} maxLength={32} /></label>
                    <label className="text-sm font-medium text-slate-700">Business name (unverified until Meta checks it)<input className={field} value={data.verified_name} onChange={e => setData('verified_name', e.target.value)} maxLength={160} /></label>
                    <label className="text-sm font-medium text-slate-700 sm:col-span-2">Access token<input type="password" autoComplete="new-password" className={field} value={data.access_token} onChange={e => setData('access_token', e.target.value)} required={!editingId} placeholder={editingId ? 'Leave blank to keep the existing encrypted token' : 'Paste Meta system-user access token'} />{errors.access_token && <span className="text-red-600">{errors.access_token}</span>}</label>
                    {editingId && <label className="text-sm font-medium text-slate-700">Connection state<select className={field} value={data.status} onChange={e => setData('status', e.target.value as ConnectionForm['status'])}><option value="pending">Pending</option><option value="disabled">Disabled</option></select></label>}
                    <div className="flex gap-2 sm:col-span-2"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{editingId ? 'Update connection' : 'Save connection'}</button>{editingId && <button type="button" onClick={() => { reset(); setEditingId(null); }} className="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700">Cancel edit</button>}</div>
                </form>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Configured phone numbers</h2>
                    {connections.length === 0 ? <p className="mt-4 text-sm text-slate-500">No WhatsApp connections configured.</p> : <ul className="mt-4 divide-y divide-slate-100">
                        {connections.map(connection => <li key={connection.id} className="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div><p className="font-semibold text-slate-800">{connection.display_phone_number || connection.phone_number_id} <span className="text-sm font-normal text-slate-500">· {connection.status}</span></p><p className="text-sm text-slate-500">Phone ID: {connection.phone_number_id} · WABA: {connection.waba_id}</p>{connection.verified_name && <p className="text-sm text-slate-600">{connection.verified_name}</p>}</div>
                            <div className="flex gap-2">{connection.status === 'pending' && <button type="button" onClick={() => router.post('/business/whatsapp/' + connection.id + '/verify')} className="rounded-lg border border-blue-200 px-3 py-2 text-sm text-blue-700">Verify with Meta</button>}{connection.status === 'active' && <button type="button" onClick={() => router.post('/business/whatsapp/' + connection.id + '/templates/sync')} className="rounded-lg border border-emerald-200 px-3 py-2 text-sm text-emerald-700">Sync templates</button>}<button type="button" onClick={() => edit(connection)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Edit</button><button type="button" onClick={() => router.delete('/business/whatsapp/' + connection.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Remove</button></div>
                        </li>)}
                    </ul>}
                </section>

                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Meta message templates</h2>
                    <p className="mt-1 text-sm text-slate-500">Template status is synchronized from Meta. Only templates approved by Meta can be used for business-initiated messages outside the 24-hour customer-service window.</p>
                    {templates.length === 0 ? <p className="mt-4 text-sm text-slate-500">No templates synchronized yet. Verify a connection, then select Sync templates.</p> : <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b text-slate-500"><th className="py-2 pr-4">Template</th><th className="py-2 pr-4">Language</th><th className="py-2 pr-4">Category</th><th className="py-2">Meta status</th></tr></thead><tbody>{templates.map(template => <tr key={template.id} className="border-b border-slate-100"><td className="py-3 pr-4 font-medium text-slate-800">{template.name}</td><td className="py-3 pr-4">{template.language}</td><td className="py-3 pr-4">{template.category ?? '—'}</td><td className="py-3">{template.status}</td></tr>)}</tbody></table></div>}
                </section>
            </div>
        </main>
    );
}
