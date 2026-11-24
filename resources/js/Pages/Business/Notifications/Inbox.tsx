import { Head, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

type NotificationItem = {
    id: string;
    title: string;
    message: string;
    conversation_id: string | null;
    created_at: string | null;
    read_at: string | null;
};

type Props = {
    notifications: NotificationItem[];
    unread_count: number;
};

export default function NotificationInbox() {
    const { notifications, unread_count } = usePage<Props>().props;

    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['notifications', 'unread_count'], preserveState: true, preserveScroll: true });
            }
        };
        const interval = window.setInterval(refresh, 15000);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, []);

    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Notification inbox" />
            <div className="mx-auto max-w-4xl">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                    <div className="flex flex-wrap gap-3 text-sm">
                        <a href="/business/notifications" className="text-blue-700 hover:underline">Email preferences</a>
                        <button
                            type="button"
                            disabled={unread_count === 0}
                            onClick={() => router.post('/business/notifications/read-all', {}, { preserveScroll: true })}
                            className="font-semibold text-slate-700 underline disabled:text-slate-400 disabled:no-underline"
                        >
                            Mark all as read
                        </button>
                    </div>
                </div>

                <div className="mt-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-bold text-slate-900">Notification inbox</h1>
                        <p className="mt-2 text-sm text-slate-600">Workspace updates and staff handover alerts.</p>
                    </div>
                    <p className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-800">{unread_count} unread</p>
                </div>

                <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    {notifications.length === 0 ? (
                        <p className="p-8 text-center text-sm text-slate-500">No notifications for this workspace yet.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {notifications.map(item => (
                                <li key={item.id} className={item.read_at ? 'p-5' : 'border-l-4 border-blue-600 bg-blue-50/40 p-5'}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h2 className="font-semibold text-slate-900">{item.title}</h2>
                                                {!item.read_at && <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">New</span>}
                                            </div>
                                            <p className="mt-1 text-sm text-slate-600">{item.message}</p>
                                            <p className="mt-2 text-xs text-slate-400">{item.created_at ? new Date(item.created_at).toLocaleString() : 'Recently'}</p>
                                        </div>
                                        <div className="flex shrink-0 flex-wrap gap-3 text-sm">
                                            {item.conversation_id && <a href={'/conversations/' + item.conversation_id} className="font-semibold text-blue-700 hover:underline">Open conversation</a>}
                                            {!item.read_at && <button type="button" onClick={() => router.post('/business/notifications/' + item.id + '/read', {}, { preserveScroll: true })} className="text-slate-600 underline">Mark read</button>}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <p className="mt-3 text-xs text-slate-500">The inbox refreshes every 15 seconds while this tab is visible. Real-time push/WebSocket delivery is not enabled.</p>
            </div>
        </main>
    );
}
