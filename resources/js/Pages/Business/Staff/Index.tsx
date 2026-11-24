import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type StaffMember = { id: string; user_id: number | null; name: string; email: string | null; phone: string | null; active: boolean; service_ids: string[] };
type ServiceOption = { id: string; name: string };
type Invitation = { id: string; email: string; role: string; expires_at: string };
type Props = { staff: StaffMember[]; services: ServiceOption[]; invitations: Invitation[] };
type StaffForm = { name: string; email: string; phone: string; active: boolean; service_ids: string[] };
const emptyForm: StaffForm = { name: '', email: '', phone: '', active: true, service_ids: [] };

export default function BusinessStaff() {
    const { staff, services, invitations } = usePage<Props>().props;
    const inviteForm = useForm({ email: '', role: 'staff' });
    const { data, setData, post, put, processing, errors, reset } = useForm<StaffForm>(emptyForm);
    const [editingId, setEditingId] = useState<string | null>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => { reset(); setEditingId(null); } };
        if (editingId) put('/business/staff/' + editingId, options);
        else post('/business/staff', options);
    }

    function edit(member: StaffMember) {
        setEditingId(member.id);
        setData({ name: member.name, email: member.email ?? '', phone: member.phone ?? '', active: member.active, service_ids: member.service_ids });
    }

    function sendInvitation(event: FormEvent) {
        event.preventDefault();
        inviteForm.post('/business/invitations', { onSuccess: () => inviteForm.reset('email') });
    }

    function toggleService(id: string, checked: boolean) {
        setData('service_ids', checked ? [...data.service_ids, id] : data.service_ids.filter(value => value !== id));
    }

    const field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="Staff management" />
            <div className="mx-auto max-w-4xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">Staff management</h1>
                <p className="mt-2 text-slate-600">Add team members and assign the services they can provide.</p>
                <form onSubmit={submit} className="mt-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-2">
                    <label className="text-sm font-medium text-slate-700">Full name<input className={field} value={data.name} onChange={e => setData('name', e.target.value)} maxLength={160} required />{errors.name && <span className="text-red-600">{errors.name}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Email<input type="email" className={field} value={data.email} onChange={e => setData('email', e.target.value)} />{errors.email && <span className="text-red-600">{errors.email}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Phone<input className={field} value={data.phone} onChange={e => setData('phone', e.target.value)} maxLength={32} /></label>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700"><input type="checkbox" checked={data.active} onChange={e => setData('active', e.target.checked)} /> Active</label>
                    <fieldset className="sm:col-span-2">
                        <legend className="text-sm font-semibold text-slate-700">Services this staff member can provide</legend>
                        {services.length === 0 ? <p className="mt-2 text-sm text-slate-500">Create services before assigning them to staff.</p> : <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {services.map(service => <label key={service.id} className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={data.service_ids.includes(service.id)} onChange={e => toggleService(service.id, e.target.checked)} />{service.name}</label>)}
                        </div>}
                        {errors.service_ids && <p className="mt-1 text-sm text-red-600">{errors.service_ids}</p>}
                    </fieldset>
                    <div className="flex gap-2 sm:col-span-2"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{editingId ? 'Update staff member' : 'Add staff member'}</button>{editingId && <button type="button" onClick={() => { reset(); setEditingId(null); }} className="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700">Cancel edit</button>}</div>
                </form>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Invite a workspace member</h2>
                    <p className="mt-1 text-sm text-slate-600">Invitations expire after seven days. Staff and manager roles are available.</p>
                    <form onSubmit={sendInvitation} className="mt-4 flex flex-wrap items-end gap-3">
                        <label className="min-w-56 flex-1 text-sm font-medium text-slate-700">Email address<input type="email" required maxLength={254} className={field} value={inviteForm.data.email} onChange={e => inviteForm.setData('email', e.target.value)} />{inviteForm.errors.email && <span className="text-red-600">{inviteForm.errors.email}</span>}</label>
                        <label className="text-sm font-medium text-slate-700">Role<select className={field} value={inviteForm.data.role} onChange={e => inviteForm.setData('role', e.target.value)}><option value="staff">Staff</option><option value="manager">Manager</option></select></label>
                        <button disabled={inviteForm.processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">Send invitation</button>
                    </form>
                    {invitations.length > 0 && <div className="mt-6"><h3 className="font-semibold text-slate-800">Pending invitations</h3><ul className="mt-2 divide-y divide-slate-100">{invitations.map(invitation => <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span>{invitation.email} · {invitation.role} · expires {new Date(invitation.expires_at).toLocaleString()}</span><button type="button" onClick={() => router.delete('/business/invitations/' + invitation.id)} className="text-red-700 underline">Revoke</button></li>)}</ul></div>}
                </section>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Team members</h2>
                    {staff.length === 0 ? <p className="mt-4 text-sm text-slate-500">No staff members configured.</p> : <ul className="mt-4 divide-y divide-slate-100">
                        {staff.map(member => <li key={member.id} className="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div><p className="font-semibold text-slate-800">{member.name} <span className="text-sm font-normal text-slate-500">· {member.active ? 'Active' : 'Inactive'}{member.user_id ? ' · Account linked' : ''}</span></p><p className="text-sm text-slate-500">{[member.email, member.phone].filter(Boolean).join(' · ') || 'No contact details'}</p><p className="mt-1 text-sm text-slate-600">{member.service_ids.map(id => services.find(service => service.id === id)?.name).filter(Boolean).join(', ') || 'No services assigned'}</p></div>
                            <div className="flex gap-2"><a href={'/business/staff/' + member.id + '/hours'} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Working hours</a><a href={'/business/staff/' + member.id + '/exceptions'} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Exceptions</a><button type="button" onClick={() => edit(member)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Edit</button><button type="button" onClick={() => router.delete('/business/staff/' + member.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Remove</button></div>
                        </li>)}
                    </ul>}
                </section>
            </div>
        </main>
    );
}
