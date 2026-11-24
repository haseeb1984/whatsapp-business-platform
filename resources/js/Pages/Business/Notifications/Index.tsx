import FormErrorSummary from '../../../Components/FormErrorSummary';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Preferences = {
    email_conversation_assignments: boolean;
    email_handover_requests: boolean;
};

export default function NotificationPreferences() {
    const { preferences } = usePage<{ preferences: Preferences }>().props;
    const form = useForm<Preferences>(preferences);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/business/notifications');
    }

    return (
        <main className="min-h-screen bg-slate-50 px-5 py-8 sm:px-8">
            <Head title="Notification preferences" />
            <div className="mx-auto max-w-3xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Notification preferences</h1>
                <p className="mt-2 text-slate-600">Choose which workspace events should send email notifications to your account. These settings apply only to your membership in this workspace.</p>

                <form onSubmit={submit} className="mt-6 space-y-5 rounded-xl border border-slate-200 bg-white p-6">
                    <FormErrorSummary errors={form.errors} />
                    <label className="flex items-start gap-3">
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4"
                            checked={form.data.email_conversation_assignments}
                            onChange={event => form.setData('email_conversation_assignments', event.target.checked)}
                        />
                        <span>
                            <span className="block font-semibold text-slate-800">Conversation assignments</span>
                            <span className="mt-1 block text-sm text-slate-600">Email me when a conversation is assigned to me.</span>
                        </span>
                    </label>
                    {form.errors.email_conversation_assignments && <p className="text-sm text-red-700">{form.errors.email_conversation_assignments}</p>}

                    <label className="flex items-start gap-3 border-t border-slate-100 pt-5">
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4"
                            checked={form.data.email_handover_requests}
                            onChange={event => form.setData('email_handover_requests', event.target.checked)}
                        />
                        <span>
                            <span className="block font-semibold text-slate-800">Staff handover requests</span>
                            <span className="mt-1 block text-sm text-slate-600">Email me when a conversation is waiting for staff attention and is assigned to me, or when no member is assigned and I am a workspace admin or manager.</span>
                        </span>
                    </label>
                    {form.errors.email_handover_requests && <p className="text-sm text-red-700">{form.errors.email_handover_requests}</p>}

                    <div className="border-t border-slate-100 pt-5">
                        <button disabled={form.processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">
                            {form.processing ? 'Saving…' : 'Save preferences'}
                        </button>
                    </div>
                </form>
                <p className="mt-4 text-xs text-slate-500">Email delivery uses the workspace mail configuration and queue. Inbox updates continue to use the existing in-app polling refresh.</p>
            </div>
        </main>
    );
}
