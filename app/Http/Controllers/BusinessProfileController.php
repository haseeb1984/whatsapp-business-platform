<?php

namespace App\\Http\\Controllers;

use App\\Models\\BusinessProfile;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\Rule;
use Inertia\\Inertia;
use Inertia\\Response;

class BusinessProfileController extends Controller
{
    public function edit(): Response
    {
        $profile = BusinessProfile::query()->first();

        return Inertia::render('Business/Profile/Edit', [
            'profile' => $profile,
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:2048'],
            'address' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['required', 'string', 'max:12', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
        ]);

        $profile = BusinessProfile::query()->first();

        if ($profile) {
            $profile->fill($validated)->save();
        } else {
            BusinessProfile::query()->create($validated);
        }

        return back()->with('success', 'Business profile saved.');
    }
}
