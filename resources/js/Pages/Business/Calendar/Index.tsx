import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type CalendarOption = { id: string; summary: string; primary: boolean; access_role: string };
type StaffOption = { id: string; name: string; google_calendar_id: string | null };
type Props = {
    connection: null | { connected: boolean; account_email: string | null; default_calendar_id: string; connected_at: string | null };
    oauth_configured: boolean;
    staff: StaffOption[];
};

function StaffCalendarRow({ member, calendars }: { member: StaffOption; calendars: CalendarOption[] }) {
    const form = useForm({ calendar_id: member.google_calendar_id || '' });
    return (
        <form onSubmit={event => { event.preventDefault(); form.put('/business/calendar/staff/' + member.id); }} className="flex flex-wrap items-center gap-3 border-t border-slate-100 py-3">
            <span className="min-w-40 flex-1 text-sm font-medium text-slate-800">{member.name}</span>
            <select className="min-w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" value={form.data.calendar_id} onChange={event => form.setData('calendar_id', event.target.value)}>
                <option value="">Use tenant default calendar</option>
                {calendars.map(calendar => <option key={calendar.id} value={calendar.id}>{calendar.summary}{calendar.primary ? ' (Primary)' : ''}</option>)}
            </select>
            <button disabled={form.processing} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Save</button>
        </form>
    );
}

export default function GoogleCalendarSettings() {
    const { connection, oauth_configured, staff } = usePage<Props>().props;
    const [calendars, setCalendars] = useState<CalendarOption[]>([]);
    const [calendarError, setCalendarError] = useState('');
    const [loading, setLoading] = useState(false);
    const defaultCalendar = useForm({ calendar_id: connection?.default_calendar_id || 'primary' });

    useEffect(() => {
        if (!connection?.connected) return;
        setLoading(true);
        fetch('/business/calendar/calendars', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async response => {
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Could not load calendars.');
                setCalendars(payload.calendars || []);
            })
            .catch(error => setCalendarError(error instanceof Error ? error.message : 'Could not load calendars.'))
            .finally(() => setLoading(false));
    }, [connection?.connected]);

    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Google Calendar" />
            <div className="mx-auto max-w-4xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Google Calendar</h1>
                <p className="mt-2 text-slate-600">Connect a Google account to check staff calendar availability and synchronize appointments. OAuth tokens are encrypted at rest and never sent to the browser.</p>
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Connection status</h2>
                    {connection?.connected ? <>
                        <p className="mt-3 text-sm text-emerald-700">Connected</p>
                        <p className="mt-1 text-sm text-slate-700">{connection.account_email || 'Google account'}</p>
                        <p className="mt-1 text-xs text-slate-500">Connected {connection.connected_at ? new Date(connection.connected_at).toLocaleString() : ''}</p>
                        <button onClick={() => router.delete('/business/calendar')} className="mt-4 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700">Disconnect Google Calendar</button>
                    </> : <>
                        <p className="mt-3 text-sm text-slate-600">No Google Calendar account is connected.</p>
                        <button disabled={!oauth_configured} onClick={() => window.location.assign('/business/calendar/connect')} className="mt-4 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Connect Google Calendar</button>
                        {!oauth_configured && <p className="mt-2 text-sm text-amber-700">Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in the server environment first.</p>}
                    </>}
                </section>
                {connection?.connected && <>
                    <section className="mt-5 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold text-slate-900">Writable calendars</h2>
                        {loading && <p className="mt-2 text-sm text-slate-500">Loading calendars…</p>}
                        {calendarError && <p className="mt-2 text-sm text-red-700">{calendarError}</p>}
                        {!loading && calendars.length > 0 && <form onSubmit={event => { event.preventDefault(); defaultCalendar.put('/business/calendar/default'); }} className="mt-3 flex flex-wrap items-end gap-3">
                            <label className="min-w-64 flex-1 text-sm font-medium text-slate-700">Tenant default calendar<select className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={defaultCalendar.data.calendar_id} onChange={event => defaultCalendar.setData('calendar_id', event.target.value)}>{calendars.map(item => <option key={item.id} value={item.id}>{item.summary}{item.primary ? ' (Primary)' : ''}</option>)}</select></label>
                            <button disabled={defaultCalendar.processing} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Save default</button>
                        </form>}
                    </section>
                    <section className="mt-5 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold text-slate-900">Staff calendar mappings</h2>
                        <p className="mt-1 text-sm text-slate-500">Each staff member can use a separate calendar or inherit the tenant default.</p>
                        {staff.map(member => <StaffCalendarRow key={member.id} member={member} calendars={calendars} />)}
                    </section>
                </>}
                <section className="mt-5 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="font-semibold text-slate-900">Permissions requested</h2>
                    <ul className="mt-2 list-disc pl-5 text-sm text-slate-600"><li>Basic Google account identity and email</li><li>Read free/busy availability</li><li>Create, update and delete appointment events</li></ul>
                    <p className="mt-3 text-xs text-slate-500">Google Calendar event synchronization and FreeBusy checks are being wired into booking orchestration. A local temporary hold is not a confirmed booking.</p>
                </section>
            </div>
        </main>
    );
}
