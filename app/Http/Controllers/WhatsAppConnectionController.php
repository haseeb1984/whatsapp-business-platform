<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConnection;
use App\Services\Meta\MetaWhatsAppGraphApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppConnectionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Business/WhatsApp/Index', [
            'connections' => WhatsAppConnection::query()->orderBy('created_at')->get()->map(
                fn (WhatsAppConnection $connection): array => [
                    'id' => $connection->id,
                    'phone_number_id' => $connection->phone_number_id,
                    'waba_id' => $connection->waba_id,
                    'display_phone_number' => $connection->display_phone_number,
                    'verified_name' => $connection->verified_name,
                    'status' => $connection->status,
                    'verified_at' => $connection->verified_at?->toIso8601String(),
                ],
            )->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, null, true);
        $validated['status'] = 'pending';
        WhatsAppConnection::query()->create($validated);

        return back()->with('success', 'WhatsApp connection details saved securely. Provider verification is still pending.');
    }

    public function update(Request $request, WhatsAppConnection $connection): RedirectResponse
    {
        $validated = $this->validated($request, $connection);
        $tokenChanged = isset($validated['access_token']) && $validated['access_token'] !== '';
        if (! $tokenChanged) {
            unset($validated['access_token']);
        }

        $identityChanged = $validated['phone_number_id'] !== $connection->phone_number_id
            || $validated['waba_id'] !== $connection->waba_id;
        $requestedStatus = $validated['status'] ?? null;
        $connection->fill($validated);

        if ($requestedStatus === 'disabled') {
            $connection->status = 'disabled';
            $connection->verified_at = null;
        } elseif ($tokenChanged || $identityChanged || $requestedStatus === 'pending') {
            $connection->status = 'pending';
            $connection->verified_at = null;
        }

        $connection->save();

        return back()->with('success', 'WhatsApp connection updated. Verify it with Meta before using it.');
    }

    public function verify(WhatsAppConnection $connection, MetaWhatsAppGraphApi $graphApi): RedirectResponse
    {
        abort_if($connection->status === 'disabled', 403);

        $data = $graphApi->verifyPhoneNumber($connection);
        $connection->forceFill([
            'display_phone_number' => $data['display_phone_number'] ?? $connection->display_phone_number,
            'verified_name' => $data['verified_name'] ?? $connection->verified_name,
            'status' => 'active',
            'verified_at' => now(),
        ])->save();

        return back()->with('success', 'Meta verified this WhatsApp phone number. The connection is active.');
    }

    public function destroy(WhatsAppConnection $connection): RedirectResponse
    {
        $connection->delete();

        return back()->with('success', 'WhatsApp connection removed.');
    }

    private function validated(Request $request, ?WhatsAppConnection $connection, bool $creating = false): array
    {
        $uniquePhoneId = Rule::unique('whatsapp_connections', 'phone_number_id');
        if ($connection !== null) {
            $uniquePhoneId->ignore($connection->id);
        }

        return $request->validate([
            'phone_number_id' => ['required', 'string', 'max:64', 'regex:/^\d+$/', $uniquePhoneId],
            'waba_id' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'verified_name' => ['nullable', 'string', 'max:160'],
            'access_token' => [$creating ? 'required' : 'nullable', 'string', 'min:20', 'max:5000'],
            'status' => ['sometimes', Rule::in(['pending', 'disabled'])],
        ]);
    }
}
