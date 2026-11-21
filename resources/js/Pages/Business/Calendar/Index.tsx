import { Head, router, usePage } from '@inertiajs/react';

type Props = {
    connection: null | { connected: boolean; account_email: string | null; default_calendar_id: string; connected_at: string | null };
    oauth_configured: boolean;
};

export default function GoogleCalendarSettings() {
    const { connection, oauth_configured } = usePage<Props>().props;
    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Google Calendar" />
            <div className="mx-auto max-w-3xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Google Calendar</h1>
                <p className="mt-2 text-slate-600">Connect a Google account to support calendar availability and appointment event synchronization. OAuth tokens are encrypted at rest and never sent to the browser.</p>
                <section className="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Connection status</h2>
                    {connection?.connected ? <>
                        <p className="mt-3 text-sm text-emerald-700">Connected</p>
                        <p className="mt-1 text-sm text-slate-700">{connection.account_email || 'Google account'}</p>
                        <p className="mt-1 text-xs text-slate-500">Default calendar: {connection.default_calendar_id}</p>
                        <button onClick={() => router.delete('/business/calendar')} className="mt-4 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700">Disconnect Google Calendar</button>
                    </> : <>
                        <p className="mt-3 text-sm text-slate-600">No Google Calendar account is connected.</p>
                        <button disabled={!oauth_configured} onClick={() => window.location.assign('/business/calendar/connect')} className="mt-4 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Connect Google Calendar</button>
                        {!oauth_configured && <p className="mt-2 text-sm text-amber-700">OAuth credentials are not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in the server environment first.</p>}
                    </>}
                </section>
                <section className="mt-5 rounded-xl border border-slate-200 bg-white p-5">
                    <h2 className="font-semibold text-slate-900">Scopes requested</h2>
                    <ul className="mt-2 list-disc pl-5 text-sm text-slate-600"><li>Basic Google account identity and email</li><li>Read free/busy availability</li><li>Create, update and delete appointment events</li></ul>
                    <p className="mt-3 text-xs text-slate-500">Calendar operations are not yet automatically wired into the booking workflow. Existing appointment holds remain unconfirmed until that orchestration is implemented and verified.</p>
                </section>
            </div>
        </main>
    );
}
