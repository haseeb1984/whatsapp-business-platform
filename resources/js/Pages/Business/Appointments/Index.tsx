import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type ServiceOption = { id: string; name: string };
type CustomerOption = { id: string; label: string };
type StaffOption = { id: string; name: string };
type Slot = { staff_id: string; staff_name: string; starts_at: string; ends_at: string; local_start: string; local_end: string; timezone: string; utc_offset: string };
type Appointment = {
    id: string; customer: string | null; service: string; staff: string | null; starts_at: string; ends_at: string;
    status: string; calendar_sync_status: string; hold_expires_at: string | null; hold_expires_label: string | null; starts_at_label: string; price: string;
};
type Props = {
    appointments: Appointment[]; services: ServiceOption[]; customers: CustomerOption[]; staff: StaffOption[];
    today_local: string; timezone: string;
};

export default function AppointmentManagement() {
    const { appointments, services, customers, staff, today_local, timezone } = usePage<Props>().props;
    const [serviceId, setServiceId] = useState(services[0]?.id ?? '');
    const [customerId, setCustomerId] = useState(customers[0]?.id ?? '');
    const [staffId, setStaffId] = useState('');
    const [date, setDate] = useState(today_local);
    const [slots, setSlots] = useState<Slot[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    async function findSlots() {
        if (!serviceId || !date) {
            setError('Choose a service and date first.');
            return;
        }
        setLoading(true);
        setError('');
        setSlots([]);
        const params = new URLSearchParams({ service_id: serviceId, date });
        if (staffId) params.set('staff_id', staffId);
        try {
            const response = await fetch('/business/appointments/availability?' + params.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok) {
                setError(payload.message || 'Availability could not be loaded.');
            } else {
                setSlots(payload.slots || []);
            }
        } catch {
            setError('Availability could not be loaded. Please try again.');
        } finally {
            setLoading(false);
        }
    }

    function createHold(slot: Slot) {
        if (!customerId) {
            setError('Create or receive a customer conversation before placing a hold.');
            return;
        }
        setError('');
        router.post('/business/appointments/holds', {
            customer_id: customerId,
            service_id: serviceId,
            staff_id: slot.staff_id,
            starts_at: slot.starts_at,
            idempotency_key: crypto.randomUUID(),
            source: 'dashboard',
        }, { onSuccess: () => { setSlots([]); } });
    }

    const field = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Appointments" />
            <div className="mx-auto max-w-6xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <div className="mt-4"><h1 className="text-3xl font-bold text-slate-900">Appointments</h1><p className="mt-2 text-slate-600">Check live staff availability and create temporary booking holds. Holds are not confirmed until Calendar synchronization succeeds.</p><p className="mt-1 text-xs text-slate-500">Business timezone: {timezone}</p></div>
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Find available times</h2>
                    <div className="mt-4 grid gap-3 sm:grid-cols-4">
                        <label className="text-sm font-medium text-slate-700">Service<select className={field} value={serviceId} onChange={event => setServiceId(event.target.value)}><option value="">Choose service</option>{services.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                        <label className="text-sm font-medium text-slate-700">Date<input type="date" min={today_local} className={field} value={date} onChange={event => setDate(event.target.value)} /></label>
                        <label className="text-sm font-medium text-slate-700">Staff member<select className={field} value={staffId} onChange={event => setStaffId(event.target.value)}><option value="">Any eligible staff</option>{staff.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                        <label className="text-sm font-medium text-slate-700">Customer<select className={field} value={customerId} onChange={event => setCustomerId(event.target.value)}><option value="">Choose customer</option>{customers.map(item => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
                    </div>
                    <button onClick={findSlots} disabled={loading || !serviceId} className="mt-4 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{loading ? 'Checking…' : 'Find availability'}</button>
                    {error && <p className="mt-3 text-sm text-red-700">{error}</p>}
                    {slots.length > 0 && <div className="mt-5 border-t border-slate-100 pt-4"><h3 className="font-semibold text-slate-900">{slots.length} available start times</h3><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {slots.map(slot => <div key={slot.staff_id + slot.starts_at} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3"><div><p className="font-semibold text-slate-900">{slot.local_start}–{slot.local_end} ({slot.utc_offset})</p><p className="text-xs text-slate-500">{slot.staff_name}</p></div><button onClick={() => createHold(slot)} className="rounded-lg border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-800">Place hold</button></div>)}
                    </div></div>}
                    {!loading && !error && slots.length === 0 && <p className="mt-3 text-sm text-slate-500">Choose a service/date and check availability. No slot is reserved until you place a hold.</p>}
                </section>
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Recent appointments and holds</h2>
                    {appointments.length === 0 ? <p className="mt-3 text-sm text-slate-500">No appointments yet.</p> : <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase text-slate-500"><tr><th className="py-2 pr-3">Customer / service</th><th className="py-2 pr-3">Staff</th><th className="py-2 pr-3">Time</th><th className="py-2 pr-3">Status</th><th className="py-2 pr-3">Price</th><th className="py-2">Action</th></tr></thead><tbody className="divide-y divide-slate-100">{appointments.map(item => <tr key={item.id}><td className="py-3 pr-3"><p className="font-medium text-slate-800">{item.customer || 'Customer'}</p><p className="text-xs text-slate-500">{item.service}</p></td><td className="py-3 pr-3">{item.staff || '—'}</td><td className="py-3 pr-3 whitespace-nowrap">{item.starts_at_label}</td><td className="py-3 pr-3"><span className="rounded-full bg-amber-50 px-2 py-1 text-xs text-amber-800">{item.status === 'pending_hold' ? 'Temporary hold' : item.status}</span><p className="mt-1 text-xs text-slate-500">{item.calendar_sync_status}</p></td><td className="py-3 pr-3">{item.price}</td><td className="py-3">{item.status === 'pending_hold' && <button onClick={() => router.post('/business/appointments/' + item.id + '/cancel-hold')} className="text-xs text-red-700 hover:underline">Cancel hold</button>}</td></tr>)}</tbody></table></div>}
                </section>
            </div>
        </main>
    );
}
