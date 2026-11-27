<?php

namespace App\Http\Controllers;

use App\Models\InventoryAsset;
use App\Models\PlanItem;
use App\Models\PpmpOrderLine;
use App\Models\PpmpReceipt;
use App\Services\InventoryRegistration;
use App\Services\PlanningAccess;
use App\Services\PpmpReceiving;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PpmpReceivingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['procurement', 'inventory', 'gso', 'admin', 'office']);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:120']])['search'] ?? '';
        $orders = PpmpOrderLine::query()
            ->whereHas('item.plan', fn ($query) => $query->visibleTo($user)->where('type', 'ppmp')->where('status', 'approved'))
            ->when($search !== '', fn ($query) => $query->whereLike('reference', '%'.$search.'%'))
            ->with(['item.plan.office:id,name'])->withSum('receipts as received', 'quantity')
            ->orderByDesc('ordered_on')->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn (PpmpOrderLine $order) => [
                'id' => $order->id,
                'item' => $order->item->name,
                'unit' => $order->item->unit,
                'office' => $order->item->plan->office->name,
                'quarter' => $order->quarter,
                'year' => $order->item->plan->fiscal_year,
                'reference' => $order->reference,
                'quantity' => $order->quantity,
                'received' => (int) $order->received,
                'url' => route('inventory.ppmp-receiving.show', ['item' => $order->plan_item_id, 'quarter' => $order->quarter]),
            ]);

        return Inertia::render('inventory/orders', ['orders' => $orders, 'search' => $search]);
    }

    public function show(Request $request, PlanItem $item, int $quarter): Response
    {
        [$sheet, $activity, $planned] = $this->scope($request, $item, $quarter);
        $orders = $this->orders($item, $quarter)
            ->with(['receipts' => fn ($query) => $query->orderBy('received_on')->orderBy('id')])
            ->withSum('receipts as received', 'quantity')->orderBy('ordered_on')->orderBy('id')->get()
            ->map(fn (PpmpOrderLine $order) => [
                ...$order->only(['id', 'reference', 'quantity', 'register_equipment']),
                'ordered_on' => $order->ordered_on->toDateString(),
                'received' => (int) $order->received,
                'receipts' => $order->receipts->map(fn (PpmpReceipt $receipt) => [
                    ...$receipt->only(['id', 'reference', 'quantity']),
                    'received_on' => $receipt->received_on->toDateString(),
                ]),
            ]);

        return Inertia::render('inventory/ppmp-receiving', [
            'item' => $item->only(['id', 'code', 'name', 'unit']),
            'sheet' => $sheet->only(['reference', 'fiscal_year']),
            'office' => $sheet->office->name,
            'quarter' => $quarter,
            'planned' => $planned,
            'orders' => $orders,
            'ppmpUrl' => route('ppmp.show', ['ppa' => $activity, 'sheet' => $sheet]),
            'canOrder' => $request->user()->role === 'procurement',
            'canReceive' => $request->user()->role === 'inventory',
            'today' => now('Asia/Manila')->toDateString(),
            'message' => $request->session()->get('success'),
        ]);
    }

    public function storeOrder(Request $request, PlanItem $item, int $quarter): RedirectResponse
    {
        PlanningAccess::roles($request->user(), ['procurement']);
        $this->scope($request, $item, $quarter);
        $this->normalizeReference($request);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'ordered_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01',
                'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'quantity' => ['required', 'integer', 'min:1'],
            'register_equipment' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $item, $quarter, $data) {
            $current = PlanItem::query()->lockForUpdate()->findOrFail($item->id);
            [, , $planned] = $this->scope($request, $current, $quarter);
            $orders = $this->orders($current, $quarter);

            if ((clone $orders)->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'This PO is already linked to this item and planned quarter.']);
            }

            $remaining = $planned - (int) $orders->sum('quantity');
            if ((int) $data['quantity'] > $remaining) {
                throw ValidationException::withMessages(['quantity' => "Only {$remaining} planned units remain to order for this quarter."]);
            }

            PpmpOrderLine::create([
                ...$data, 'plan_item_id' => $current->id, 'quarter' => $quarter, 'recorded_by' => $request->user()->id,
            ]);
        }, 3);

        return to_route('inventory.ppmp-receiving.show', ['item' => $item, 'quarter' => $quarter])
            ->with('success', 'Purchase order linked.');
    }

    public function storeReceipt(Request $request, PlanItem $item, int $quarter, PpmpOrderLine $order): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['inventory']);
        [$sheet] = $this->scope($request, $item, $quarter);
        abort_unless($order->plan_item_id === $item->id && $order->quarter === $quarter, 404);
        $this->normalizeReference($request);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$order->ordered_on->toDateString(),
                'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'quantity' => ['required', 'integer', 'min:1'],
            'units' => ['nullable', 'array', 'list', 'max:250'],
            'units.*' => ['required', 'array:property_tag,serial_number'],
            'units.*.property_tag' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'units.*.serial_number' => ['nullable', 'string', 'max:120'],
        ]);
        $units = collect($data['units'] ?? [])->map(fn ($unit) => [
            'property_tag' => strtoupper(trim($unit['property_tag'])),
            'serial_number' => $unit['serial_number'] ?? null,
        ]);
        $quantity = (int) $data['quantity'];

        if ($order->register_equipment ? $units->count() !== $quantity : $units->isNotEmpty()) {
            throw ValidationException::withMessages([
                'units' => $order->register_equipment
                    ? 'Enter one property tag for each accepted unit. You can receive up to 250 tagged units per delivery.'
                    : 'This order tracks quantities and does not need property tags.',
            ]);
        }

        try {
            DB::transaction(function () use ($order, $sheet, $item, $user, $data, $quantity, $units) {
                $current = PpmpOrderLine::query()->lockForUpdate()->findOrFail($order->id);
                if ($current->receipts()->where('reference', $data['reference'])->exists()) {
                    throw ValidationException::withMessages(['reference' => 'This delivery reference is already recorded for this order line.']);
                }

                $remaining = $current->quantity - (int) $current->receipts()->sum('quantity');
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages(['quantity' => "Only {$remaining} ordered units are still pending."]);
                }

                if ($units->isNotEmpty() && InventoryAsset::whereIn('property_tag', $units->pluck('property_tag'))->exists()) {
                    throw ValidationException::withMessages(['units' => 'A property tag on this receipt is already in Inventory.']);
                }

                $receipt = $current->receipts()->create([
                    'reference' => $data['reference'], 'received_on' => $data['received_on'],
                    'quantity' => $quantity, 'recorded_by' => $user->id,
                ]);
                foreach ($units as $unit) {
                    $asset = InventoryRegistration::register([
                        ...$unit, 'name' => mb_substr($item->name, 0, 150), 'received_on' => $data['received_on'],
                        'office_id' => $sheet->office_id, 'receipt_reference' => $current->reference,
                    ], $user);
                    $receipt->assets()->attach($asset);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['units' => 'A property tag was registered by another user. Check the tags and retry.']);
        }

        return to_route('inventory.ppmp-receiving.show', ['item' => $item, 'quarter' => $quarter])
            ->with('success', 'Accepted delivery recorded.');
    }

    private function scope(Request $request, PlanItem $item, int $quarter): array
    {
        $sheet = $item->plan;
        abort_unless($sheet?->type === 'ppmp' && $sheet->status === 'approved', 404);
        $activity = $sheet->parent;
        abort_unless($activity && $activity->office_id === $sheet->office_id && $activity->fiscal_year === $sheet->fiscal_year, 404);
        PlanningAccess::viewPpmpWorkspace($request->user(), $activity);
        $planned = PpmpReceiving::quantity($item, $quarter);
        abort_unless($planned > 0, 404);

        return [$sheet, $activity, $planned];
    }

    private function orders(PlanItem $item, int $quarter)
    {
        return PpmpOrderLine::where('plan_item_id', $item->id)->where('quarter', $quarter);
    }

    private function normalizeReference(Request $request): void
    {
        $reference = $request->input('reference');
        if (is_string($reference)) {
            $request->merge(['reference' => strtoupper(trim($reference))]);
        }
    }
}
