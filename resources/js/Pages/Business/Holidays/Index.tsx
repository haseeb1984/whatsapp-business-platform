import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Holiday = { id: string; date: string; name: string; is_closed: boolean; opens_at: string | null; closes_at: string | null };
type Props = { holidays: Holiday[] };

export default function BusinessHolidays() {
    const { holidays } = usePage<Props>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        date: '',
        name: '',
        is_closed: true,
        opens_at: '09:00',
        closes_at: '17:00',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/business/holidays', { onSuccess: () => reset('date', 'name') });
    }

    const field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="Business holidays" />
            <div className="mx-auto max-w-4xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Holidays and special hours</h1>
                <p className="mt-2 text-slate-600">Add a full-day closure or special opening hours for a specific business-local date.</p>
                <form onSubmit={submit} className="mt-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-2">
                    <label className="text-sm font-medium text-slate-700">Date<input type="date" className={field} value={data.date} onChange={e => setData('date', e.target.value)} required />{errors.date && <span className="text-red-600">{errors.date}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Name<input className={field} value={data.name} onChange={e => setData('name', e.target.value)} maxLength={160} placeholder="Public holiday" required />{errors.name && <span className="text-red-600">{errors.name}</span>}</label>
                    <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" checked={data.is_closed} onChange={e => setData('is_closed', e.target.checked)} /> Closed all day</label>
                    {!data.is_closed && <><label className="text-sm text-slate-700">Opens<input type="time" className={field} value={data.opens_at} onChange={e => setData('opens_at', e.target.value)} />{errors.opens_at && <span className="text-red-600">{errors.opens_at}</span>}</label><label className="text-sm text-slate-700">Closes<input type="time" className={field} value={data.closes_at} onChange={e => setData('closes_at', e.target.value)} />{errors.closes_at && <span className="text-red-600">{errors.closes_at}</span>}</label></>}
                    <div className="sm:col-span-2"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{processing ? 'Saving…' : 'Add holiday'}</button></div>
                </form>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Configured dates</h2>
                    {holidays.length === 0 ? <p className="mt-4 text-sm text-slate-500">No holiday dates configured.</p> : <ul className="mt-4 divide-y divide-slate-100">
                        {holidays.map(holiday => <li key={holiday.id} className="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div><p className="font-semibold text-slate-800">{holiday.name}</p><p className="text-sm text-slate-500">{holiday.date} · {holiday.is_closed ? 'Closed all day' : holiday.opens_at + '–' + holiday.closes_at}</p></div>
                            <button type="button" onClick={() => router.delete('/business/holidays/' + holiday.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50">Remove</button>
                        </li>)}
                    </ul>}
                </section>
                <p className="mt-4 text-xs text-slate-500">Special hours apply to one date only. Weekly hours remain unchanged. Overnight special hours are not supported.</p>
            </div>
        </main>
    );
}
