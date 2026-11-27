<?php

namespace App\Http\Controllers;

use App\Models\InventoryAsset;
use App\Models\InventoryMovement;
use App\Models\Office;
use App\Models\User;
use App\Services\InventoryRegistration;
use App\Services\PlanningAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    private const ASSET_VIEWERS = ['inventory', 'gso', 'office', 'admin'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, [...self::ASSET_VIEWERS, 'procurement']);

        $filters = $request->validate([
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
            'year' => ['required_with:quarter', 'nullable', 'integer', 'between:1900,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $department = $user->role === 'office';
        $receivingView = $user->role === 'procurement';
        $officeId = isset($filters['office_id']) ? (int) $filters['office_id'] : null;
        if ($department) {
            abort_if($officeId !== null && $officeId !== $user->office_id, 403);
            $officeId = $user->office_id;
        }

        $year = isset($filters['year']) ? (int) $filters['year'] : null;
        $quarter = isset($filters['quarter']) ? (int) $filters['quarter'] : null;
        $search = $filters['search'] ?? '';
        $assets = InventoryAsset::query()->with($receivingView ? 'receivedForOffice:id,name' : 'office:id,name');
        if ($officeId !== null) {
            $assets->where($receivingView ? 'received_for_office_id' : 'office_id', $officeId);
        } elseif ($department) {
            $assets->whereRaw('1 = 0');
        }

        if ($year !== null) {
            $start = CarbonImmutable::create($year, ($quarter === null ? 1 : 3 * ($quarter - 1) + 1), 1);
            $end = $quarter === null ? $start->addYear() : $start->addMonths(3);
            $assets->where('received_on', '>=', $start->toDateString())
                ->where('received_on', '<', $end->toDateString());
        }

        if ($search !== '') {
            $assets->where(function ($query) use ($search, $receivingView) {
                $query->whereLike('name', '%'.$search.'%')
                    ->orWhereLike('property_tag', '%'.$search.'%')
                    ->orWhereLike('receipt_reference', '%'.$search.'%');
                if (! $receivingView) {
                    $query->orWhereLike('serial_number', '%'.$search.'%');
                }
            });
        }

        $offices = Office::query()
            ->when($department, fn ($query) => $query->whereKey($user->office_id))
            ->orderBy('name')->orderBy('id')->get(['id', 'code', 'name']);

        return Inertia::render('inventory/index', [
            'offices' => $offices,
            'assets' => $assets->orderByDesc('received_on')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(function (InventoryAsset $asset) use ($receivingView) {
                    $summary = [
                        'id' => $asset->id,
                        'property_tag' => $asset->property_tag,
                        'name' => $asset->name,
                        'office' => $receivingView ? $asset->receivedForOffice->name : $asset->office->name,
                        'received_on' => $asset->received_on->toDateString(),
                        'receipt_reference' => $asset->receipt_reference,
                    ];

                    if (! $receivingView) {
                        $summary['serial_number'] = $asset->serial_number;
                        $summary['holder_name'] = $asset->holder_name;
                    }

                    return $summary;
                }),
            'filters' => ['office_id' => $officeId, 'year' => $year, 'quarter' => $quarter, 'search' => $search],
            'department' => $department,
            'receivingView' => $receivingView,
            'canManage' => $user->role === 'inventory',
        ]);
    }

    public function show(Request $request, InventoryAsset $asset): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, self::ASSET_VIEWERS);
        abort_if($user->role === 'office' && $asset->office_id !== $user->office_id, 403);

        $asset->load(['office:id,name', 'receivedForOffice:id,name']);
        $canManage = $user->role === 'inventory';
        $canViewHistory = $user->role !== 'office';

        return Inertia::render('inventory/show', [
            'asset' => [
                ...$asset->only(['id', 'property_tag', 'name', 'serial_number', 'receipt_reference',
                    'holder_name', 'holder_id', 'office_id', 'version']),
                'received_on' => $asset->received_on->toDateString(),
                'office' => $asset->office->name,
                'received_for_office' => $asset->receivedForOffice->name,
            ],
            'history' => $canViewHistory ? $asset->movements()->with(['fromOffice:id,name', 'toOffice:id,name'])
                ->latest('id')->paginate(15)->through(fn (InventoryMovement $movement) => [
                    ...$movement->only(['id', 'action', 'from_holder_name', 'to_holder_name', 'actor_name', 'remarks']),
                    'from_office' => $movement->fromOffice?->name,
                    'to_office' => $movement->toOffice->name,
                    'recorded_at' => $movement->created_at->toIso8601String(),
                ]) : null,
            'offices' => $canManage ? Office::orderBy('name')->get(['id', 'code', 'name']) : [],
            'employees' => $canManage ? User::whereNotNull('office_id')->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'office_id']) : [],
            'canManage' => $canManage,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['inventory']);

        $tag = $request->input('property_tag');
        if (is_string($tag)) {
            $request->merge(['property_tag' => strtoupper(trim($tag))]);
        }

        $data = $request->validate([
            'property_tag' => ['required', 'string', 'max:80', 'unique:inventory_assets,property_tag'],
            'name' => ['required', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'receipt_reference' => ['nullable', 'string', 'max:120'],
            'office_id' => ['required', 'integer', 'exists:offices,id'],
        ]);

        try {
            $asset = DB::transaction(fn () => InventoryRegistration::register($data, $user), 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['property_tag' => 'This property tag is already registered.']);
        }

        return to_route('inventory.show', $asset)->with('success', 'Equipment registered.');
    }

    public function move(Request $request, InventoryAsset $asset): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['inventory']);

        $data = $request->validate([
            'action' => ['required', Rule::in(['assign', 'transfer', 'return'])],
            'version' => ['required', 'integer', 'min:1'],
            'destination_office_id' => ['required_if:action,transfer', 'nullable', 'integer', 'exists:offices,id'],
            'holder_id' => ['required_if:action,assign', 'nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($asset, $data, $user) {
            $current = InventoryAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($current->version !== (int) $data['version']) {
                $this->movementError('This equipment changed while you were editing. Reload its details and try again.');
            }

            $action = $data['action'];
            if ($action === 'assign' && $current->holder_name !== null) {
                $this->movementError('This equipment already has a holder. Use Transfer to change the holder.');
            }
            if ($action === 'return' && $current->holder_name === null) {
                $this->movementError('This equipment is already available.');
            }

            $destination = $action === 'transfer' ? (int) $data['destination_office_id'] : $current->office_id;
            $holder = null;
            if ($action !== 'return' && isset($data['holder_id'])) {
                $holder = User::whereKey($data['holder_id'])->where('office_id', $destination)->lockForUpdate()->first();
                if ($holder === null) {
                    throw ValidationException::withMessages([
                        'holder_id' => 'Choose a user assigned to the receiving office.',
                    ]);
                }
            }

            if ($action === 'transfer' && $destination === $current->office_id
                && ($holder === null || $holder->id === $current->holder_id)) {
                $this->movementError('Choose a different office or a different holder for the transfer.');
            }

            $current->movements()->create([
                'action' => $action,
                'from_office_id' => $current->office_id,
                'to_office_id' => $destination,
                'from_holder_id' => $current->holder_id,
                'from_holder_name' => $current->holder_name,
                'to_holder_id' => $holder?->id,
                'to_holder_name' => $holder?->name,
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $current->update([
                'office_id' => $destination,
                'holder_id' => $holder?->id,
                'holder_name' => $holder?->name,
                'version' => $current->version + 1,
            ]);
        }, 3);

        return to_route('inventory.show', $asset)->with('success', match ($data['action']) {
            'assign' => 'Equipment assigned.',
            'transfer' => 'Equipment transferred.',
            'return' => 'Equipment returned and available for assignment.',
        });
    }

    private function movementError(string $message): never
    {
        throw ValidationException::withMessages(['movement' => $message]);
    }
}
