<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\User;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdministrationController extends Controller
{
    private const ROLES = [
        'admin' => 'System Administrator',
        'office' => 'Department / Office',
        'budget' => 'Budget Office',
        'mayor' => 'Mayor / Approving Authority',
        'procurement' => 'BAC / Procurement Office',
        'gso' => 'General Services Office',
        'inventory' => 'Supply / Inventory Office',
    ];

    public function index(Request $request): Response
    {
        PlanningAccess::roles($request->user(), ['admin']);

        return Inertia::render('administration', [
            'offices' => Office::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'office_id']),
            'roles' => self::ROLES,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function storeOffice(Request $request): RedirectResponse
    {
        PlanningAccess::roles($request->user(), ['admin']);

        $request->merge([
            'code' => is_string($request->input('code'))
                ? strtoupper(trim($request->input('code')))
                : $request->input('code'),
        ]);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9-]+$/',
                Rule::unique('offices', 'code'),
            ],
            'name' => ['required', 'string', 'max:255'],
        ], [
            'code.regex' => 'Use letters, numbers, and hyphens only.',
        ]);

        DB::transaction(function () use ($request, $data) {
            $office = Office::create($data);

            PlanningService::audit(
                $request->user(),
                'office',
                $office->id,
                'created',
                $office->id,
                null,
                ['code' => $office->code, 'name' => $office->name],
            );
        });

        return to_route('administration.index')
            ->with('success', 'Office created successfully.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        PlanningAccess::roles($request->user(), ['admin']);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'office_id' => [
                'nullable',
                'required_if:role,office',
                'integer',
                Rule::exists('offices', 'id'),
            ],
        ]);

        // Keep the current administrator from removing their own access.
        if ($request->user()->id === $user->id && $data['role'] !== 'admin') {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove your own administrator role.',
            ]);
        }

        DB::transaction(function () use ($request, $user, $data) {
            $account = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $before = [
                'role' => $account->role,
                'office_id' => $account->office_id,
            ];

            $account->update([
                'role' => $data['role'],
                'office_id' => $data['office_id'] ?? null,
            ]);

            PlanningService::audit(
                $request->user(),
                'user',
                $account->id,
                'access updated',
                $account->office_id,
                null,
                [
                    'before' => $before,
                    'after' => [
                        'role' => $account->role,
                        'office_id' => $account->office_id,
                    ],
                ],
            );
        });

        return to_route('administration.index')
            ->with('success', 'User role and office updated successfully.');
    }
}