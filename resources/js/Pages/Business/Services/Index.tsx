import FormErrorSummary from '../../../Components/FormErrorSummary';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Service = {
    id: string;
    name: string;
    description: string | null;
    price_amount: string;
    currency: string;
    duration_minutes: number;
    buffer_before_minutes: number;
    buffer_after_minutes: number;
    active: boolean;
};
type Props = { services: Service[] };
type FormData = Omit<Service, 'id' | 'price_amount'> & { price_amount: string };

const emptyForm: FormData = {
    name: '',
    description: '',
    price_amount: '0.00',
    currency: 'PKR',
    duration_minutes: 30,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    active: true,
};

export default function BusinessServices() {
    const { services } = usePage<Props>().props;
    const { data, setData, post, put, processing, errors, reset } = useForm<FormData>(emptyForm);
    const [editingId, setEditingId] = useState<string | null>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => { reset(); setEditingId(null); } };
        if (editingId) put('/business/services/' + editingId, options);
        else post('/business/services', options);
    }

    function edit(service: Service) {
        setEditingId(service.id);
        setData({
            name: service.name,
            description: service.description ?? '',
            price_amount: service.price_amount,
            currency: service.currency,
            duration_minutes: service.duration_minutes,
            buffer_before_minutes: service.buffer_before_minutes,
            buffer_after_minutes: service.buffer_after_minutes,
            active: service.active,
        });
    }

    const field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="Services and pricing" />
            <div className="mx-auto max-w-5xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Services and pricing</h1>
                <p className="mt-2 text-slate-600">Configure bookable services, currency, duration and scheduling buffers.</p>
                <form onSubmit={submit} className="mt-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-2">
                    <FormErrorSummary errors={errors} />
                    <label className="text-sm font-medium text-slate-700">Service name<input className={field} value={data.name} onChange={e => setData('name', e.target.value)} maxLength={120} required />{errors.name && <span className="text-red-600">{errors.name}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Description<input className={field} value={data.description ?? ''} onChange={e => setData('description', e.target.value)} maxLength={3000} /></label>
                    <label className="text-sm font-medium text-slate-700">Price<input type="number" min="0" step="0.01" className={field} value={data.price_amount} onChange={e => setData('price_amount', e.target.value)} required />{errors.price_amount && <span className="text-red-600">{errors.price_amount}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Currency code (3 letters)<input className={field} value={data.currency} onChange={e => setData('currency', e.target.value.toUpperCase())} maxLength={3} required />{errors.currency && <span className="text-red-600">{errors.currency}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Duration (minutes)<input type="number" min="5" max="1440" className={field} value={data.duration_minutes} onChange={e => setData('duration_minutes', Number(e.target.value))} required />{errors.duration_minutes && <span className="text-red-600">{errors.duration_minutes}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Buffer before (minutes)<input type="number" min="0" max="240" className={field} value={data.buffer_before_minutes} onChange={e => setData('buffer_before_minutes', Number(e.target.value))} required />{errors.buffer_before_minutes && <span className="text-red-600">{errors.buffer_before_minutes}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Buffer after (minutes)<input type="number" min="0" max="240" className={field} value={data.buffer_after_minutes} onChange={e => setData('buffer_after_minutes', Number(e.target.value))} required />{errors.buffer_after_minutes && <span className="text-red-600">{errors.buffer_after_minutes}</span>}</label>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700"><input type="checkbox" checked={data.active} onChange={e => setData('active', e.target.checked)} /> Active and bookable</label>
                    <div className="flex gap-2 sm:col-span-2"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{editingId ? 'Update service' : 'Add service'}</button>{editingId && <button type="button" onClick={() => { reset(); setEditingId(null); }} className="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700">Cancel edit</button>}</div>
                </form>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Configured services</h2>
                    {services.length === 0 ? <p className="mt-4 text-sm text-slate-500">No services configured yet.</p> : <ul className="mt-4 divide-y divide-slate-100">
                        {services.map(service => <li key={service.id} className="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div><p className="font-semibold text-slate-800">{service.name} <span className="text-sm font-normal text-slate-500">· {service.price_amount} {service.currency}</span></p><p className="text-sm text-slate-500">{service.duration_minutes} min · {service.buffer_before_minutes} min before / {service.buffer_after_minutes} min after · {service.active ? 'Active' : 'Inactive'}</p>{service.description && <p className="mt-1 text-sm text-slate-600">{service.description}</p>}</div>
                            <div className="flex gap-2"><button type="button" onClick={() => edit(service)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Edit</button><button type="button" onClick={() => router.delete('/business/services/' + service.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Remove</button></div>
                        </li>)}
                    </ul>}
                </section>
            </div>
        </main>
    );
}
