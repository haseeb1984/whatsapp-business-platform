import FormErrorSummary from '../../../Components/FormErrorSummary';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Faq = { id: string; question: string; answer: string; locale: string; status: 'draft' | 'published' | 'archived'; published_at: string | null };
type Props = { faqs: Faq[]; filters: { search: string; status: string } };
type FaqForm = { question: string; answer: string; locale: string; status: 'draft' | 'published' | 'archived' };
const emptyForm: FaqForm = { question: '', answer: '', locale: 'en', status: 'draft' };

export default function BusinessFaqs() {
    const { faqs, filters } = usePage<Props>().props;
    const { data, setData, post, put, processing, errors, reset } = useForm<FaqForm>(emptyForm);
    const [editingId, setEditingId] = useState<string | null>(null);
    const [search, setSearch] = useState(filters.search);

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => { reset(); setEditingId(null); } };
        if (editingId) put('/business/faqs/' + editingId, options);
        else post('/business/faqs', options);
    }

    function applyFilters(nextStatus = filters.status) {
        router.get('/business/faqs', { search, status: nextStatus }, { preserveState: true, replace: true });
    }

    function edit(faq: Faq) {
        setEditingId(faq.id);
        setData({ question: faq.question, answer: faq.answer, locale: faq.locale, status: faq.status });
    }

    const field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';
    return (
        <main className="min-h-screen bg-slate-50 px-6 py-10">
            <Head title="Approved business FAQs" />
            <div className="mx-auto max-w-5xl">
                <a href="/dashboard" className="text-sm text-blue-700 hover:underline">← Dashboard</a>
                <h1 className="mt-4 text-3xl font-bold text-slate-900">FAQs and approved answers</h1>
                <p className="mt-2 text-slate-600">Only published FAQs are eligible for the future AI retrieval workflow. Keep answers accurate and business-approved.</p>
                <form onSubmit={submit} className="mt-8 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <FormErrorSummary errors={errors} />
                    <label className="text-sm font-medium text-slate-700">Question<input className={field} value={data.question} onChange={e => setData('question', e.target.value)} maxLength={500} required />{errors.question && <span className="text-red-600">{errors.question}</span>}</label>
                    <label className="text-sm font-medium text-slate-700">Approved answer<textarea className={field} rows={5} value={data.answer} onChange={e => setData('answer', e.target.value)} maxLength={10000} required />{errors.answer && <span className="text-red-600">{errors.answer}</span>}</label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">Locale<input className={field} value={data.locale} onChange={e => setData('locale', e.target.value)} placeholder="en or en-US" maxLength={12} required />{errors.locale && <span className="text-red-600">{errors.locale}</span>}</label>
                        <label className="text-sm font-medium text-slate-700">Publication status<select className={field} value={data.status} onChange={e => setData('status', e.target.value as FaqForm['status'])}><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select>{errors.status && <span className="text-red-600">{errors.status}</span>}</label>
                    </div>
                    <div className="flex gap-2"><button disabled={processing} className="rounded-lg bg-blue-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{editingId ? 'Update FAQ' : 'Add FAQ'}</button>{editingId && <button type="button" onClick={() => { reset(); setEditingId(null); }} className="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700">Cancel edit</button>}</div>
                </form>
                <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-semibold text-slate-900">Knowledge base</h2><div className="flex flex-wrap gap-2"><input aria-label="Search FAQs" className="rounded-lg border border-slate-300 px-3 py-2 text-sm" value={search} onChange={e => setSearch(e.target.value)} placeholder="Search questions and answers" /><button type="button" onClick={() => applyFilters()} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Search</button><select aria-label="Filter FAQ status" className="rounded-lg border border-slate-300 px-3 py-2 text-sm" value={filters.status} onChange={e => applyFilters(e.target.value)}><option value="all">All</option><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div></div>
                    {faqs.length === 0 ? <p className="mt-4 text-sm text-slate-500">No matching FAQs.</p> : <ul className="mt-4 divide-y divide-slate-100">
                        {faqs.map(faq => <li key={faq.id} className="py-4"><div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0 flex-1"><p className="font-semibold text-slate-800">{faq.question}</p><p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{faq.answer}</p><p className="mt-2 text-xs text-slate-500">{faq.locale} · {faq.status}</p></div><div className="flex gap-2"><button type="button" onClick={() => edit(faq)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Edit</button><button type="button" onClick={() => router.delete('/business/faqs/' + faq.id)} className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">Remove</button></div></div></li>)}
                    </ul>}
                </section>
            </div>
        </main>
    );
}
