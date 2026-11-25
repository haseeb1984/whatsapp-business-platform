import { Head, router, useForm, usePage } from '@inertiajs/react';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { useEffect, useState } from 'react';

type CalendarOption = { id: string; summary: string; primary: boolean; access_role: string };
type StaffOption = { id: string; name: string; google_calendar_id: string | null };
type Props = {
    connection: null | { connected: boolean; status: string; account_email: string | null; default_calendar_id: string; connected_at: string | null };
    oauth_configured: boolean;
    staff: StaffOption[];
    errors?: Record<string, string>;
};

function StaffCalendarRow({ member, calendars }: { member: StaffOption; calendars: CalendarOption[] }) {
    const form = useForm({ calendar_id: member.google_calendar_id || '' });
    return (
        <form onSubmit={event => { event.preventDefault(); form.put('/business/calendar/staff/' + member.id); }} className="flex flex-wrap items-center gap-3 border-t border-slate-100 py-3">
            <label htmlFor={"staff-calendar-" + member.id} className="min-w-40 flex-1 text-sm font-medium text-slate-800">{member.name}</label>
            <select id={"staff-calendar-" + member.id} aria-invalid={Boolean(form.errors.calendar_id)} aria-describedby={form.errors.calendar_id ? "staff-calendar-error-" + member.id : undefined} className="min-w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" value={form.data.calendar_id} onChange={event => form.setData('calendar_id', event.target.value)}>
                <option value="">Use tenant default calendar</option>
                {calendars.map(calendar => <option key={calendar.id} value={calendar.id}>{calendar.summary}{calendar.primary ? ' (Primary)' : ''}</option>)}
            </select>
            <button disabled={form.processing} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Save</button>
            <FormErrorSummary errors={form.errors} />
            {form.errors.calendar_id && <p id={"staff-calendar-error-" + member.id} className="text-sm text-red-700">{form.errors.calendar_id}</p>}
        </form>
    );
}

export default function GoogleCalendarSettings() {
    const { connection, oauth_configured, staff, errors = {} } = usePage<Props>().props;
    const [calendars, setCalendars] = useState<CalendarOption[]>([]);
    const [calendarError, setCalendarError] = useState('');
    const [loading, setLoading] = useState(false);
    const defaultCalendar = useForm({ calendar_id: connection?.default_calendar_id || 'primary' });

    useEffect(() => {
        if (!connection?.connected) return;
        const controller = new AbortController();
        setLoading(true);
        setCalendarError('');
        fetch('/business/calendar/calendars', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async response => {
                const payload: unknown = await response.json();
                if (!response.ok) {
                    const message = typeof payload === 'object' && payload !== null && 'message' in payload
                        && typeof payload.message === 'string'
                        ? payload.message
                        : 'Could not load calendars.';
                    throw new Error(message);
                }
                const items = typeof payload === 'object' && payload !== null && 'calendars' in payload
                    ? payload.calendars
                    : null;
                if (!Array.isArray(items)) throw new Error('Google returned an invalid calendar list.');
                setCalendars(items.filter((item): item is CalendarOption =>
                    typeof item === 'object' && item !== null
                    && 'id' in item && typeof item.id === 'string'
                    && 'summary' in item && typeof item.summary === 'string'
                    && 'primary' in item && typeof item.primary === 'boolean'
                    && 'access_role' in item && typeof item.access_role === 'string'
                ));
            })
            .catch(error => {
                if (error instanceof Error && error.name === 'AbortError') return;
                setCalendarError(error instanceof Error ? error.message : 'Could not load calendars.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [connection?.connected]);

    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Google Calendar" />
            <div className="mx-auto max-w-4xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Google Calendar</h1>
                <p className="mt-2 text-slate-600">Connect a Google account to check staff calendar availability and synchronize appointments. OAuth tokens are encrypted at rest and never sent to the browser.</p>
                {errors.calendar && <div className="mt-4"><FormErrorSummary errors={{ calendar: errors.calendar }} /></div>}
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Connection status</h2>
                    {connection?.connected ? <>
                        <p className="mt-3 text-sm text-emerald-700">Connected</p>
                        <p className="mt-1 text-sm text-slate-700">{connection.account_email || 'Google account'}</p>
                        <p className="mt-1 text-xs text-slate-500">Connected {connection.connected_at ? new Date(connection.connected_at).toLocaleString() : ''}</p>
                        <button onClick={() => router.delete('/business/calendar')} className="mt-4 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700">Disconnect Google Calendar</button>
                    </> : <>
                        {connection?.status === 'reauthorization_required' ? <>
                            <p className="mt-3 text-sm text-amber-700">Google authorization has expired or been revoked. Reconnect the Google account to resume Calendar synchronization. If Google does not issue a fresh offline token, clear the old saved authorization and connect again with consent.</p>
                            <button onClick={() => router.delete('/business/calendar')} className="mt-4 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700">Clear saved authorization</button>
                        </> : <p className="mt-3 text-sm text-slate-600">No Google Calendar account is connected.</p>}
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
                            <label htmlFor="tenant-default-calendar" className="min-w-64 flex-1 text-sm font-medium text-slate-700">Tenant default calendar<select id="tenant-default-calendar" aria-invalid={Boolean(defaultCalendar.errors.calendar_id)} aria-describedby={defaultCalendar.errors.calendar_id ? "tenant-default-calendar-error" : undefined} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={defaultCalendar.data.calendar_id} onChange={event => defaultCalendar.setData('calendar_id', event.target.value)}>{calendars.map(item => <option key={item.id} value={item.id}>{item.summary}{item.primary ? ' (Primary)' : ''}</option>)}</select></label>
                            <button disabled={defaultCalendar.processing} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Save default</button>
                            <FormErrorSummary errors={defaultCalendar.errors} />
                            {defaultCalendar.errors.calendar_id && <p id="tenant-default-calendar-error" className="mt-2 text-sm text-red-700">{defaultCalendar.errors.calendar_id}</p>}
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
                    <p className="mt-3 text-xs text-slate-500">Google FreeBusy is checked when listing availability and again before event creation. Calendar confirmation and cancellation actions are available in the appointment dashboard; local holds remain unconfirmed until the external event is created and stored.</p>
                </section>
            </div>
        </main>
    );
}
