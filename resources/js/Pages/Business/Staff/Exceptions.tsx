import FormErrorSummary from '../../../Components/FormErrorSummary';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Exception = { id: string; date: string; is_closed: boolean; opens_at: string | null; closes_at: string | null; reason: string | null };
type Staff = { id: string; name: string };
type Props = { staff: Staff; exceptions: Exception[] };

function ExceptionRow({ staffId, item }: { staffId: string; item: Exception }) {
    const form = useForm({
        date: item.date,
        is_closed: item.is_closed,
        opens_at: item.opens_at || '09:00',
        closes_at: item.closes_at || '17:00',
        reason: item.reason || '',
    });
    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/business/staff/' + staffId + '/exceptions/' + item.id);
    }
    return (
        <form onSubmit={save} className="grid gap-3 rounded-lg border border-slate-200 p-4 md:grid-cols-6">
            <FormErrorSummary errors={form.errors} />
            <label className="text-sm font-medium text-slate-700">Date<input type="date" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.data.date} onChange={event => form.setData('date', event.target.value)} /></label>
            <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700"><input type="checkbox" checked={form.data.is_closed} onChange={event => form.setData('is_closed', event.target.checked)} /> Unavailable / leave</label>
            {!form.data.is_closed && <>
                <label className="text-sm font-medium text-slate-700">Opens<input type="time" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.data.opens_at} onChange={event => form.setData('opens_at', event.target.value)} /></label>
                <label className="text-sm font-medium text-slate-700">Closes<input type="time" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.data.closes_at} onChange={event => form.setData('closes_at', event.target.value)} /></label>
            </>}
            <label className="text-sm font-medium text-slate-700 md:col-span-2">Reason<input className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" maxLength={160} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            <div className="flex items-end gap-2 md:col-span-6"><button disabled={form.processing} className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white">Save changes</button><button type="button" onClick={() => router.delete('/business/staff/' + staffId + '/exceptions/' + item.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Remove</button></div>
            {Object.values(form.errors).length > 0 && <p className="text-sm text-red-700 md:col-span-6">{Object.values(form.errors).join(' ')}</p>}
        </form>
    );
}

export default function StaffExceptions() {
    const { staff, exceptions } = usePage<Props>().props;
    const form = useForm({ date: '', is_closed: true, opens_at: '09:00', closes_at: '17:00', reason: '' });
    function create(event: FormEvent) {
        event.preventDefault();
        form.post('/business/staff/' + staff.id + '/exceptions', { onSuccess: () => form.reset('date', 'reason') });
    }
    const field = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Staff availability exceptions" />
            <div className="mx-auto max-w-5xl">
                <a href="/business/staff" className="text-sm text-blue-700 hover:underline">← Staff</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Availability exceptions</h1>
                <p className="mt-2 text-slate-600">Manage leave days or one-off working hours for {staff.name}. These dates override the weekly staff schedule.</p>
                <form onSubmit={create} className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <FormErrorSummary errors={form.errors} />
                    <h2 className="font-semibold text-slate-900">Add exception</h2>
                    <div className="mt-4 grid gap-3 sm:grid-cols-5">
                        <label className="text-sm font-medium text-slate-700">Date<input type="date" className={field} value={form.data.date} onChange={event => form.setData('date', event.target.value)} required /></label>
                        <label className="flex items-center gap-2 self-end pb-3 text-sm text-slate-700"><input type="checkbox" checked={form.data.is_closed} onChange={event => form.setData('is_closed', event.target.checked)} /> Unavailable / leave</label>
                        {!form.data.is_closed && <>
                            <label className="text-sm font-medium text-slate-700">Opens<input type="time" className={field} value={form.data.opens_at} onChange={event => form.setData('opens_at', event.target.value)} required /></label>
                            <label className="text-sm font-medium text-slate-700">Closes<input type="time" className={field} value={form.data.closes_at} onChange={event => form.setData('closes_at', event.target.value)} required /></label>
                        </>}
                        <label className="text-sm font-medium text-slate-700">Reason<input className={field} maxLength={160} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
                    </div>
                    {Object.values(form.errors).length > 0 && <p className="mt-3 text-sm text-red-700">{Object.values(form.errors).join(' ')}</p>}
                    <button disabled={form.processing} className="mt-4 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Add exception</button>
                </form>
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="font-semibold text-slate-900">Saved exceptions</h2>
                    {exceptions.length === 0 ? <p className="mt-3 text-sm text-slate-500">No exceptions recorded.</p> : <div className="mt-4 space-y-3">{exceptions.map(item => <ExceptionRow key={item.id} staffId={staff.id} item={item} />)}</div>}
                </section>
            </div>
        </main>
    );
}
