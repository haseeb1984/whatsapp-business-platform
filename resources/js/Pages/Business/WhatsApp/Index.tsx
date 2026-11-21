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
type Props = { connections: Connection[] };
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
    const { connections } = usePage<Props>().props;
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
                <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">New connections remain pending until provider verification is implemented. Do not treat a pending connection as ready to send messages.</p>
                <form onSubmit={submit} className="mt-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-2">
                    <label className="text-sm font-medium text-slate-700">Phone Number ID<input className={field} value={data.phone_number_id} onChange={e => setData('phone_number_id', e.target.value)} required maxLength={64} />{errors.phone_number_id && <span className="text-red-600">{errors.phone_number_id}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">WhatsApp Business Account ID<input className={field} value={data.waba_id} onChange={e => setData('waba_id', e.target.value)} required maxLength={64} />{errors.waba_id && <span className="text-red-600">{errors.waba_id}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Display phone number<input className={field} value={data.display_phone_number} onChange={e => setData('display_phone_number', e.target.value)} maxLength={32} /></label>
                    <label className="text-sm font-medium text-slate-700">Verified business name<input className={field} value={data.verified_name} onChange={e => setData('verified_name', e.target.value)} maxLength={160} /></label>
                    <label className="text-sm font-medium text-slate-700 sm:col-span-2">Access token<input type="password" autoComplete="new-password" className={field} value={data.access_token} onChange={e => setData('access_token', e.target.value)} required={!editingId} placeholder={editingId ? 'Leave blank to keep the existing encrypted token' : 'Paste Meta system-user access token'} />{errors.access_token && <span className="text-red-600">{errors.access_token}</span>}</label>
                    {editingId && <label className="text-sm font-medium text-slate-700">Connection state<select className={field} value={data.status} onChange={e => setData('status', e.target.value as ConnectionForm['status'])}><option value="pending">Pending</option><option value="disabled">Disabled</option></select></label>}
                    <div className="flex gap-2 sm:col-span-2"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{editingId ? 'Update connection' : 'Save connection'}</button>{editingId && <button type="button" onClick={() => { reset(); setEditingId(null); }} className="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700">Cancel edit</button>}</div>
                </form>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Configured phone numbers</h2>
                    {connections.length === 0 ? <p className="mt-4 text-sm text-slate-500">No WhatsApp connections configured.</p> : <ul className="mt-4 divide-y divide-slate-100">
                        {connections.map(connection => <li key={connection.id} className="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div><p className="font-semibold text-slate-800">{connection.display_phone_number || connection.phone_number_id} <span className="text-sm font-normal text-slate-500">· {connection.status}</span></p><p className="text-sm text-slate-500">Phone ID: {connection.phone_number_id} · WABA: {connection.waba_id}</p>{connection.verified_name && <p className="text-sm text-slate-600">{connection.verified_name}</p>}</div>
                            <div className="flex gap-2"><button type="button" onClick={() => edit(connection)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Edit</button><button type="button" onClick={() => router.delete('/business/whatsapp/' + connection.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Remove</button></div>
                        </li>)}
                    </ul>}
                </section>
            </div>
        </main>
    );
}
