import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Day = { weekday: number; label: string; is_closed: boolean; opens_at: string; closes_at: string };
type Props = { staff: { id: string; name: string }; days: Day[] };

export default function StaffWorkingHours() {
    const { staff, days } = usePage<Props>().props;
    const { data, setData, put, processing, errors } = useForm({ days });

    function updateDay(index: number, field: keyof Day, value: string | boolean | number) {
        setData('days', data.days.map((day, current) => current === index ? { ...day, [field]: value } : day));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        put('/business/staff/' + staff.id + '/hours');
    }

    const inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="Staff working hours" />
            <div className="mx-auto max-w-4xl">
                <a href="/business/staff" className="text-sm text-blue-700 hover:underline">← Staff management</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">{staff.name} · Working hours</h1>
                <p className="mt-2 text-slate-600">Set this team member's regular availability. These hours must also fit within the business opening hours.</p>
                <form onSubmit={submit} className="mt-8 space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    {data.days.map((day, index) => <div key={day.weekday} className="grid gap-3 border-b border-slate-100 pb-4 sm:grid-cols-[1fr_auto_1fr_1fr] sm:items-center">
                        <p className="font-semibold text-slate-800">{day.label}</p>
                        <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={day.is_closed} onChange={e => updateDay(index, 'is_closed', e.target.checked)} /> Closed</label>
                        <label className="text-sm text-slate-600">Opens<input aria-label={day.label + ' opening time'} type="time" className={inputClass + ' mt-1 block w-full'} value={day.opens_at} disabled={day.is_closed} onChange={e => updateDay(index, 'opens_at', e.target.value)} /></label>
                        <label className="text-sm text-slate-600">Closes<input aria-label={day.label + ' closing time'} type="time" className={inputClass + ' mt-1 block w-full'} value={day.closes_at} disabled={day.is_closed} onChange={e => updateDay(index, 'closes_at', e.target.value)} /></label>
                        {errors['days.' + index + '.closes_at'] && <p className="text-sm text-red-600 sm:col-span-4">{errors['days.' + index + '.closes_at']}</p>}
                    </div>)}
                    {errors.days && <p className="text-sm text-red-600">{errors.days}</p>}
                    <div className="flex justify-end"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{processing ? 'Saving…' : 'Save working hours'}</button></div>
                </form>
            </div>
        </main>
    );
}
