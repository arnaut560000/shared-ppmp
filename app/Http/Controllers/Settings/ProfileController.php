<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        DB::transaction(function () use ($request) {
            $adminIds = User::where('role', 'admin')->orderBy('id')
                ->lockForUpdate()->pluck('id');

            $account = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($account->role === 'admin' && $adminIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'password' => 'Assign another administrator before deleting this account.',
                ]);
            }

            if (DB::table('plans')->where('created_by', $account->id)->exists()
                || DB::table('annual_plans')->where('created_by', $account->id)->exists()) {
                throw ValidationException::withMessages([
                    'password' => 'This account created planning records and must be kept for their history.',
                ]);
            }

            $account->delete();
        }, 3);

        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
