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
        'mpdc' => 'Municipal Planning and Development Coordinator',
        'mayor' => 'Mayor / Approving Authority',
        'treasurer' => 'Municipal Treasurer',
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

        $data = $this->validateOffice($request);

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

    public function updateOffice(Request $request, Office $office): RedirectResponse
    {
        PlanningAccess::roles($request->user(), ['admin']);

        DB::transaction(function () use ($request, $office) {
            $office = Office::query()->lockForUpdate()->findOrFail($office->id);
            $data = $this->validateOffice($request, $office);
            $before = $office->only(['code', 'name']);
            $office->fill($data);

            if ($office->isDirty()) {
                $office->save();

                PlanningService::audit(
                    $request->user(),
                    'office',
                    $office->id,
                    'updated',
                    $office->id,
                    null,
                    ['before' => $before, 'after' => $office->only(['code', 'name'])],
                );
            }
        }, 3);

        return to_route('administration.index')
            ->with('success', 'Office updated successfully.');
    }

    private function validateOffice(Request $request, ?Office $office = null): array
    {
        $code = $request->input('code');
        $request->merge([
            'code' => is_string($code) ? strtoupper(trim($code)) : $code,
        ]);

        $uniqueCode = Rule::unique('offices', 'code');
        if ($office !== null) {
            $uniqueCode->ignore($office);
        }

        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9-]+$/',
                $uniqueCode,
            ],
            'name' => ['required', 'string', 'max:255'],
        ], [
            'code.regex' => 'Use letters, numbers, and hyphens only.',
        ]);
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        PlanningAccess::roles($request->user(), ['admin']);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'expected_role' => ['required', Rule::in(array_keys(self::ROLES))],
            'expected_office_id' => ['present', 'nullable', 'integer'],
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
            $adminIds = User::where('role', 'admin')->orderBy('id')
                ->lockForUpdate()->pluck('id');
            abort_unless($adminIds->contains($request->user()->id), 403);

            $account = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $expectedOffice = $data['expected_office_id'] === null ? null : (int) $data['expected_office_id'];
            $currentOffice = $account->office_id === null ? null : (int) $account->office_id;

            if ($account->role !== $data['expected_role'] || $currentOffice !== $expectedOffice) {
                throw ValidationException::withMessages([
                    'access_conflict' => 'This assignment changed in another session. Review the current role and office before saving again.',
                ]);
            }

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
        }, 3);

        return to_route('administration.index')
            ->with('success', 'User role and office updated successfully.');
    }
}
