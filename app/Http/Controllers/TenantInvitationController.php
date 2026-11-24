<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\TenantInvitation;
use App\Notifications\TenantStaffInvitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantInvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', Rule::in(['staff', 'manager'])],
        ]);
        $email = Str::lower(trim($validated['email']));

        $alreadyMember = DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenantId)
            ->whereRaw('LOWER(users.email) = ?', [$email])
            ->where('tenant_user.status', 'active')
            ->exists();

        if ($alreadyMember) {
            return back()->withErrors(['email' => 'This person is already an active workspace member.']);
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays(7);
        $invitation = DB::transaction(function () use ($tenantId, $email, $validated, $request, $token, $expiresAt): TenantInvitation {
            TenantInvitation::query()
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            return TenantInvitation::query()->create([
                'tenant_id' => $tenantId,
                'invited_by' => $request->user()->getKey(),
                'email' => $email,
                'role' => $validated['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
            ]);
        });

        $tenant = $invitation->tenant()->firstOrFail();
        Notification::route('mail', $email)->notify(new TenantStaffInvitation(
            $tenant->name,
            $request->user()->name,
            $invitation->role,
            route('invitations.show', ['token' => $token]),
            $expiresAt->toDayDateTimeString(),
        ));

        return back()->with('success', 'Invitation sent. It expires in 7 days.');
    }

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->findUsableInvitation($token);
        abort_unless(Str::lower((string) $request->user()->email) === $invitation->email, 403);

        return Inertia::render('Invitations/Show', [
            'tenantName' => $invitation->tenant()->value('name'),
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expiresAt' => $invitation->expires_at->toDayDateTimeString(),
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = DB::transaction(function () use ($request, $token): TenantInvitation {
            $record = TenantInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($record->accepted_at || $record->revoked_at || $record->expires_at->isPast(), 410);
            abort_unless(Str::lower((string) $request->user()->email) === $record->email, 403);

            $record->tenant()->firstOrFail()->users()->syncWithoutDetaching([
                $request->user()->getKey() => ['role' => $record->role, 'status' => 'active'],
            ]);

            if ($record->role === 'staff') {
                TenantContext::runForTenant($record->tenant_id, function () use ($record, $request): void {
                    $staffProfile = Staff::query()
                        ->whereRaw('LOWER(email) = ?', [$record->email])
                        ->first();

                    if ($staffProfile) {
                        abort_if($staffProfile->user_id !== null && (int) $staffProfile->user_id !== (int) $request->user()->getKey(), 409);
                        $staffProfile->forceFill(['user_id' => $request->user()->getKey()])->save();

                        return;
                    }

                    Staff::query()->create([
                        'name' => $request->user()->name,
                        'email' => $record->email,
                        'user_id' => $request->user()->getKey(),
                        'active' => true,
                    ]);
                });
            }

            $record->forceFill(['accepted_at' => now()])->save();

            return $record;
        });

        $request->session()->put('current_tenant_id', (string) $invitation->tenant_id);

        return redirect()->route('dashboard')->with('success', 'You have joined '.$invitation->tenant()->value('name').'.');
    }

    public function destroy(string $invitation): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $record = TenantInvitation::query()->where('tenant_id', $tenantId)->findOrFail($invitation);
        abort_if($record->accepted_at, 409);
        $record->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'Invitation revoked.');
    }

    private function findUsableInvitation(string $token): TenantInvitation
    {
        $record = TenantInvitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($record->accepted_at || $record->revoked_at || $record->expires_at->isPast(), 410);

        return $record;
    }
}
