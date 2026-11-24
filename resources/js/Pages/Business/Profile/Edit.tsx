import FormErrorSummary from '../../../Components/FormErrorSummary';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type Profile = {
    display_name: string;
    description: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    address: string | null;
    timezone: string;
    locale: string;
};
type Props = { profile: Profile | null; timezones: string[] };

export default function EditBusinessProfile() {
    const { profile, timezones } = usePage<Props>().props;
    const { data, setData, put, processing, errors } = useForm({
        display_name: profile?.display_name ?? '',
        description: profile?.description ?? '',
        email: profile?.email ?? '',
        phone: profile?.phone ?? '',
        website: profile?.website ?? '',
        address: profile?.address ?? '',
        timezone: profile?.timezone ?? 'UTC',
        locale: profile?.locale ?? 'en',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        put('/business/profile');
    }

    const fieldClass = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="Business profile" />
            <div className="mx-auto max-w-3xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Business profile</h1>
                <p className="mt-2 text-slate-600">Configure the business identity and regional settings used by the virtual receptionist.</p>
                <form onSubmit={submit} className="mt-8 space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <FormErrorSummary errors={errors} />
                    <Field label="Business display name" error={errors.display_name}><input className={fieldClass} value={data.display_name} onChange={e => setData('display_name', e.target.value)} required maxLength={160} /></Field>
                    <Field label="Description" error={errors.description}><textarea className={fieldClass} rows={4} value={data.description} onChange={e => setData('description', e.target.value)} maxLength={5000} /></Field>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field label="Contact email" error={errors.email}><input type="email" className={fieldClass} value={data.email} onChange={e => setData('email', e.target.value)} /></Field>
                        <Field label="Phone" error={errors.phone}><input className={fieldClass} value={data.phone} onChange={e => setData('phone', e.target.value)} maxLength={32} /></Field>
                        <Field label="Website" error={errors.website}><input type="url" className={fieldClass} value={data.website} onChange={e => setData('website', e.target.value)} placeholder="https://" /></Field>
                        <Field label="Locale" error={errors.locale}><input className={fieldClass} value={data.locale} onChange={e => setData('locale', e.target.value)} placeholder="en or en-US" /></Field>
                    </div>
                    <Field label="Address" error={errors.address}><textarea className={fieldClass} rows={3} value={data.address} onChange={e => setData('address', e.target.value)} maxLength={2000} /></Field>
                    <Field label="Business timezone" error={errors.timezone}>
                        <select className={fieldClass} value={data.timezone} onChange={e => setData('timezone', e.target.value)}>
                            {timezones.map(timezone => <option key={timezone} value={timezone}>{timezone}</option>)}
                        </select>
                    </Field>
                    <div className="flex items-center justify-between border-t border-slate-100 pt-5">
                        <p className="text-xs text-slate-500">Saved separately for each business workspace.</p>
                        <button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{processing ? 'Saving…' : 'Save profile'}</button>
                    </div>
                </form>
            </div>
        </main>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return <label className="block text-sm font-medium text-slate-700">{label}{children}{error && <span className="mt-1 block text-sm text-red-600">{error}</span>}</label>;
}
