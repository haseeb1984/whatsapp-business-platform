import { Head, router, usePage } from '@inertiajs/react';

type Workspace = { id: string; name: string; slug: string; timezone: string };
type Props = { workspaces: Workspace[]; currentTenantId: string | null; errors?: Record<string, string> };

export default function WorkspaceIndex() {
    const { workspaces, currentTenantId } = usePage<Props>().props;

    return (
        <main className="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-12">
            <Head title="Choose a workspace" />
            <h1 className="text-2xl font-semibold">Choose a business workspace</h1>
            <p className="mt-2 text-sm text-slate-600">Select which business you want to manage in this session.</p>
            {workspaces.length === 0 ? (
                <section className="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                    Your account has no active business memberships. Ask a platform administrator to review your access.
                </section>
            ) : (
                <div className="mt-8 grid gap-3">
                    {workspaces.map(workspace => (
                        <button key={workspace.id} onClick={() => router.post('/workspaces/switch', { tenant_id: workspace.id })} className="flex items-center justify-between rounded-xl border border-slate-200 p-5 text-left hover:border-slate-500 hover:bg-slate-50">
                            <span><span className="block font-semibold">{workspace.name}</span><span className="mt-1 block text-sm text-slate-500">{workspace.timezone} · {workspace.slug}</span></span>
                            <span className="text-sm font-medium">{workspace.id === currentTenantId ? 'Current' : 'Select →'}</span>
                        </button>
                    ))}
                </div>
            )}
            <button onClick={() => router.post('/logout')} className="mt-8 w-fit text-sm text-slate-600 underline">Sign out</button>
        </main>
    );
}
