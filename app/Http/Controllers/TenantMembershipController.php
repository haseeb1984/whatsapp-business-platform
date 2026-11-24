<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Staff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantMembershipController extends Controller
{
    public function updateRole(Request $request, int $user): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        abort_if((int) $request->user()->getKey() === $user, 409);
        $validated = $request->validate(['role' => ['required', Rule::in(['staff', 'manager'])]]);
        abort_if((int) $request->user()->getKey() === $user, 409);

        DB::transaction(function () use ($tenantId, $user, $validated): void {
            $membership = $this->lockMembership($tenantId, $user);
            abort_unless(in_array($membership->role, ['staff', 'manager'], true), 403);

            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $user)
                ->update(['role' => $validated['role'], 'updated_at' => now()]);
        });

        return back()->with('success', 'Workspace member role updated.');
    }

    public function suspend(Request $request, int $user): RedirectResponse
    {
        abort_if((int) $request->user()->getKey() === $user, 409);
        $tenantId = app(TenantContext::class)->id();

        DB::transaction(function () use ($tenantId, $user): void {
            $membership = $this->lockMembership($tenantId, $user);
            abort_unless(in_array($membership->role, ['staff', 'manager'], true), 403);

            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $user)
                ->update(['status' => 'suspended', 'updated_at' => now()]);

            Staff::query()->where('user_id', $user)->update(['active' => false]);
            $this->releaseAssignedConversations($user);
        });

        return back()->with('success', 'Workspace access suspended.');
    }

    public function reactivate(Request $request, int $user): RedirectResponse
    {
        abort_if((int) $request->user()->getKey() === $user, 409);
        $tenantId = app(TenantContext::class)->id();

        DB::transaction(function () use ($tenantId, $user): void {
            $membership = $this->lockMembership($tenantId, $user);
            abort_unless(in_array($membership->role, ['staff', 'manager'], true), 403);
            abort_if($membership->status !== 'suspended', 409);

            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $user)
                ->update(['status' => 'active', 'updated_at' => now()]);

            Staff::query()->where('user_id', $user)->update(['active' => true]);
        });

        return back()->with('success', 'Workspace access reactivated.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        abort_if((int) $request->user()->getKey() === $user, 409);
        $tenantId = app(TenantContext::class)->id();

        DB::transaction(function () use ($tenantId, $user): void {
            $membership = $this->lockMembership($tenantId, $user);
            abort_unless(in_array($membership->role, ['staff', 'manager'], true), 403);

            Staff::query()->where('user_id', $user)->update(['user_id' => null, 'active' => false]);
            $this->releaseAssignedConversations($user);
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $user)->delete();
        });

        return back()->with('success', 'Workspace access removed.');
    }

    private function releaseAssignedConversations(int $userId): void
    {
        Conversation::query()->where('assigned_user_id', $userId)->get()->each(function (Conversation $conversation): void {
            $conversation->forceFill([
                'assigned_user_id' => null,
                'automation_state' => $conversation->status === 'open' && $conversation->automation_state === 'HUMAN_ACTIVE'
                    ? 'WAITING_FOR_STAFF'
                    : $conversation->automation_state,
            ])->save();
        });
    }

    private function lockMembership(string $tenantId, int $user): object
    {
        return DB::table('tenant_user')->where('tenant_id', $tenantId)
            ->where('user_id', $user)->lockForUpdate()->first()
            ?? abort(404);
    }
}
