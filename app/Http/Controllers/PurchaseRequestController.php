<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    private const RESERVED = ['awaiting_treasurer', 'awaiting_number', 'numbered'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['office', 'treasurer', 'gso', 'procurement', 'admin']);
        abort_if($user->role === 'office' && ! $user->office_id, 403);
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'pr' => ['nullable', 'integer', 'min:1'],
            'create' => ['nullable', 'boolean'],
        ]);
        $year = (int) ($filters['year'] ?? now('Asia/Manila')->year);

        $app = DB::table('annual_procurement_plans')->where('fiscal_year', $year)->where('status', 'approved')->first();
        $requests = DB::table('purchase_requests as pr')
            ->join('offices', 'offices.id', '=', 'pr.office_id')
            ->where('pr.app_id', $app?->id ?? 0)
            ->when($user->role === 'office', fn ($query) => $query->where('pr.office_id', $user->office_id))
            ->when($user->role === 'procurement', fn ($query) => $query->where('pr.status', 'numbered'))
            ->when($user->role === 'gso', fn ($query) => $query->whereIn('pr.status', ['awaiting_number', 'numbered']))
            ->select(['pr.id', 'pr.version', 'pr.purpose', 'pr.status', 'pr.pr_number', 'pr.review_note', 'offices.name as office']);

        $selected = isset($filters['pr']) ? (clone $requests)->where('pr.id', $filters['pr'])->first() : null;
        abort_if(isset($filters['pr']) && ! $selected, 404);
        $editing = $user->role === 'office' && $app
            && (($selected?->status === 'returned') || (! $selected && ! empty($filters['create'])));

        return Inertia::render('purchase-requests', [
            'year' => $year,
            'approvedApp' => $app !== null,
            'requests' => $requests->latest('pr.id')->paginate(15)->appends(['year' => $year]),
            'selected' => $selected,
            'items' => $selected ? $this->requestItems($selected->id, $user->role === 'procurement') : [],
            'available' => $editing ? $this->available($app->id, $user->office_id)->get() : [],
            'canRequest' => $user->role === 'office' && $app !== null,
            'editing' => (bool) $editing,
            'canReview' => $user->role === 'treasurer' && $selected?->status === 'awaiting_treasurer',
            'canNumber' => $user->role === 'gso' && $selected?->status === 'awaiting_number',
            'history' => $selected ? DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.user_id')
                ->where('subject_type', 'purchase_request')->where('subject_id', $selected->id)->latest('audit_events.id')
                ->get(['audit_events.id', 'action', 'comment', 'audit_events.created_at', 'users.name as actor']) : [],
            'message' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function resubmit(Request $request, int $pr): RedirectResponse
    {
        return $this->save($request, $pr);
    }

    public function decide(Request $request, int $pr): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['treasurer', 'gso']);
        $number = $request->input('pr_number');
        if (is_string($number)) {
            $request->merge(['pr_number' => strtoupper(trim($number))]);
        }
        $data = $request->validate([
            'action' => ['required', 'in:approve,return,number'],
            'version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'required_if:action,return', 'string', 'max:2000'],
            'pr_number' => ['nullable', 'required_if:action,number', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._\\/-]*$/'],
        ]);
        $action = $data['action'];
        PlanningAccess::roles($user, [$action === 'number' ? 'gso' : 'treasurer']);

        try {
            $year = DB::transaction(function () use ($pr, $user, $data, $action) {
                $record = DB::table('purchase_requests')->where('id', $pr)->first();
                abort_unless($record, 404);
                Office::whereKey($record->office_id)->lockForUpdate()->firstOrFail();
                $record = DB::table('purchase_requests')->where('id', $pr)->lockForUpdate()->first();
                $expected = $action === 'number' ? 'awaiting_number' : 'awaiting_treasurer';
                if ($record->status !== $expected || (int) $record->version !== (int) $data['version']) {
                    PlanningService::fail('This PR changed. Reload it before continuing.');
                }

                $status = match ($action) {
                    'approve' => 'awaiting_number',
                    'return' => 'returned',
                    'number' => 'numbered',
                };
                $fields = [
                    'status' => $status, 'version' => (int) $record->version + 1,
                    'review_note' => $action === 'return' ? $data['comment'] : null, 'updated_at' => now(),
                ];
                if ($action === 'number') {
                    $fields['pr_number'] = $data['pr_number'];
                    $fields['numbered_at'] = now();
                }
                DB::table('purchase_requests')->where('id', $pr)->update($fields);
                PlanningService::audit($user, 'purchase_request', $pr, $status, $record->office_id,
                    $data['comment'] ?? null, ['pr_number' => $fields['pr_number'] ?? null]);

                return (int) DB::table('annual_procurement_plans')->where('id', $record->app_id)->value('fiscal_year');
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            if ($action !== 'number') {
                throw $error;
            }
            throw ValidationException::withMessages(['pr_number' => 'That official PR number is already used for this fiscal year.']);
        }

        return to_route('procurement.requests.index', ['year' => $year, 'pr' => $pr])
            ->with('success', match ($action) {
                'approve' => 'PR approved and sent to GSO for numbering.',
                'return' => 'PR returned to its office.',
                'number' => 'Official PR number saved. The PR is available to BAC.',
            });
    }

    private function save(Request $request, ?int $pr = null): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['office']);
        abort_unless($user->office_id, 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'version' => $pr === null ? ['nullable'] : ['required', 'integer', 'min:1'],
            'purpose' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:app_source_line_id,quantity'],
            'items.*.app_source_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:4000000'],
        ]);

        $id = DB::transaction(function () use ($data, $user, $pr) {
            Office::whereKey($user->office_id)->lockForUpdate()->firstOrFail();
            $app = DB::table('annual_procurement_plans')->where('fiscal_year', $data['year'])
                ->where('status', 'approved')->first();
            if (! $app) {
                PlanningService::fail('An approved APP is required for this fiscal year.');
            }

            if ($pr !== null) {
                $record = DB::table('purchase_requests')->where('id', $pr)->where('office_id', $user->office_id)
                    ->where('app_id', $app->id)->lockForUpdate()->first();
                abort_unless($record, 404);
                if ($record->status !== 'returned' || (int) $record->version !== (int) $data['version']) {
                    PlanningService::fail('Only the current version of a returned PR can be resubmitted.');
                }
            }

            $available = $this->available($app->id, $user->office_id)
                ->whereIn('app_source_lines.id', array_column($data['items'], 'app_source_line_id'))
                ->get()->keyBy('id');
            foreach ($data['items'] as $line) {
                $source = $available->get($line['app_source_line_id']);
                if (! $source || (int) $line['quantity'] > (int) $source->remaining) {
                    throw ValidationException::withMessages([
                        'items' => 'A requested line is outside your approved APP or exceeds its remaining quantity. Review the available balance.',
                    ]);
                }
            }

            $fields = [
                'status' => 'awaiting_treasurer', 'purpose' => $data['purpose'],
                'review_note' => null, 'updated_at' => now(),
            ];
            if ($pr === null) {
                $pr = DB::table('purchase_requests')->insertGetId([
                    ...$fields, 'app_id' => $app->id, 'office_id' => $user->office_id,
                    'created_at' => now(), 'created_by' => $user->id,
                ]);
                $event = 'submitted';
            } else {
                DB::table('purchase_requests')->where('id', $pr)->update([
                    ...$fields, 'version' => (int) $record->version + 1,
                ]);
                DB::table('purchase_request_lines')->where('purchase_request_id', $pr)->delete();
                $event = 'resubmitted';
            }
            DB::table('purchase_request_lines')->insert(array_map(
                fn ($item) => [...$item, 'purchase_request_id' => $pr], $data['items']
            ));
            PlanningService::audit($user, 'purchase_request', $pr, $event, $user->office_id, metadata: ['items' => $data['items']]);

            return $pr;
        }, 3);

        return to_route('procurement.requests.index', ['year' => $data['year'], 'pr' => $id])
            ->with('success', 'PR sent to the Treasurer for review.');
    }

    private function available(int $appId, int $officeId): Builder
    {
        $reserved = DB::table('purchase_request_lines as used')
            ->join('purchase_requests as request', 'request.id', '=', 'used.purchase_request_id')
            ->where('request.app_id', $appId)->where('request.office_id', $officeId)
            ->whereIn('request.status', self::RESERVED)
            ->groupBy('used.app_source_line_id')
            ->selectRaw('used.app_source_line_id, SUM(used.quantity) AS quantity');

        return DB::table('app_source_lines')
            ->leftJoinSub($reserved, 'reserved', 'reserved.app_source_line_id', '=', 'app_source_lines.id')
            ->where('app_source_lines.app_id', $appId)->where('app_source_lines.office_id', $officeId)
            ->orderBy('app_source_lines.name')->orderBy('app_source_lines.quarter')->orderBy('app_source_lines.id')
            ->select(['app_source_lines.id', 'app_source_lines.code', 'app_source_lines.name',
                'app_source_lines.unit', 'app_source_lines.quarter', 'app_source_lines.ppmp_reference'])
            ->selectRaw('app_source_lines.quantity - COALESCE(reserved.quantity, 0) AS remaining');
    }

    private function requestItems(int $pr, bool $bac): array
    {
        return DB::table('purchase_request_lines as lines')
            ->join('app_source_lines as source', 'source.id', '=', 'lines.app_source_line_id')
            ->join('plan_items', 'plan_items.id', '=', 'source.plan_item_id')
            ->join('plans as ppmp', 'ppmp.id', '=', 'plan_items.plan_id')
            ->where('lines.purchase_request_id', $pr)->orderBy('source.name')->orderBy('source.quarter')->orderBy('lines.id')
            ->get(['lines.quantity', 'lines.app_source_line_id', 'source.code', 'source.name', 'source.unit',
                'source.quarter', 'source.ppmp_reference', 'source.plan_item_id', 'ppmp.id as sheet_id', 'ppmp.parent_id'])
            ->map(fn ($item) => [
                'app_source_line_id' => $item->app_source_line_id, 'quantity' => $item->quantity,
                'code' => $item->code, 'name' => $item->name, 'unit' => $item->unit,
                'quarter' => $item->quarter, 'ppmp_reference' => $item->ppmp_reference,
                'sourceUrl' => ! $bac ? null : (Route::has('inventory.ppmp-receiving.show')
                    ? route('inventory.ppmp-receiving.show', ['item' => $item->plan_item_id, 'quarter' => $item->quarter])
                    : route('ppmp.show', ['ppa' => $item->parent_id, 'sheet' => $item->sheet_id])),
            ])->all();
    }
}
