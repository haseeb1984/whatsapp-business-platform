<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(): Response { return Inertia::render('Auth/Login'); }
    public function showRegister(): Response { return Inertia::render('Auth/Register'); }
    public function showForgotPassword(): Response { return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]); }
    public function showResetPassword(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => $request->query('email', ''),
        ]);
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        // Keep the response identical for existing and unknown email addresses.
        return back()->with('status', 'If an account matches that email address, a password reset link has been sent.');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => [__($status)]]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The supplied credentials are incorrect.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $memberships = $request->user()->tenants()->wherePivot('status', 'active')->get();

        if ($memberships->count() === 1) {
            $request->session()->put('current_tenant_id', (string) $memberships->first()->getKey());
            return redirect()->intended(route('dashboard'));
        }

        $request->session()->forget('current_tenant_id');
        return redirect()->route('workspaces.index');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'business_name' => ['required', 'string', 'max:160'],
            'timezone' => ['required', 'timezone'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        [$user, $tenant] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $baseSlug = Str::slug($validated['business_name']) ?: 'business';
            $slug = $baseSlug;
            $suffix = 2;
            while (Tenant::query()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            $tenant = Tenant::query()->create([
                'name' => $validated['business_name'],
                'slug' => $slug,
                'timezone' => $validated['timezone'],
                'status' => 'active',
            ]);

            $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
            return [$user, $tenant];
        });

        Auth::login($user);
        $request->session()->regenerate();
        $user->sendEmailVerificationNotification();
        $request->session()->put('current_tenant_id', (string) $tenant->getKey());

        return redirect()->route('verification.notice');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
