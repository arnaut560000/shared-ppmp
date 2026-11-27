<?php

namespace App\Http\Controllers;

use App\Models\PlanItem;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnnualProcurementController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['procurement', 'mayor', 'treasurer', 'gso', 'office', 'admin']);
        abort_if($user->role === 'office' && ! $user->office_id, 403);
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = (int) ($data['year'] ?? now('Asia/Manila')->year);

        $app = DB::table('annual_procurement_plans')->where('fiscal_year', $year)
            ->when(in_array($user->role, ['treasurer', 'gso', 'office'], true),
                fn ($query) => $query->where('status', 'approved'))
            ->first(['id', 'status', 'version', 'review_note']);

        $lines = DB::table('app_source_lines as lines')
            ->join('offices', 'offices.id', '=', 'lines.office_id')
            ->where('lines.app_id', $app?->id ?? 0)
            ->when($user->role === 'office', fn ($query) => $query->where('lines.office_id', $user->office_id));

        return Inertia::render('annual-procurement-plan', [
            'year' => $year,
            'app' => $app,
            'totalCents' => (int) (clone $lines)->selectRaw('COALESCE(SUM(lines.quantity * lines.price_cents), 0) as total')->value('total'),
            'lines' => $lines->orderBy('offices.name')->orderBy('lines.name')->orderBy('lines.quarter')->orderBy('lines.id')
                ->paginate(20, [
                    'lines.id', 'lines.ppmp_reference', 'lines.code', 'lines.name', 'lines.specification',
                    'lines.unit', 'lines.expense_class', 'lines.quarter', 'lines.quantity', 'lines.price_cents',
                    'offices.name as office',
                ])->withQueryString(),
            'canBuild' => $user->role === 'procurement' && (! $app || in_array($app->status, ['draft', 'returned'], true)),
            'canApprove' => $user->role === 'mayor' && $app?->status === 'submitted',
            'message' => $request->session()->get('success'),
        ]);
    }

    public function build(Request $request, int $year): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['procurement']);
        $this->validateYear($year);

        DB::transaction(function () use ($year, $user) {
            $app = DB::table('annual_procurement_plans')->where('fiscal_year', $year)->lockForUpdate()->first();
            if ($app && ! in_array($app->status, ['draft', 'returned'], true)) {
                PlanningService::fail('Only a draft or returned APP can be rebuilt.');
            }

            $lines = $this->eligibleLines($year);
            if (! $lines) {
                PlanningService::fail('No approved PPMP items with an attested AIP were found for this year.');
            }
            $attributes = [
                'source_hash' => $this->hash($lines),
                'updated_at' => now(),
            ];

            if ($app) {
                $id = $app->id;
                DB::table('annual_procurement_plans')->where('id', $id)->update([
                    ...$attributes, 'version' => (int) $app->version + 1,
                ]);
                DB::table('app_source_lines')->where('app_id', $id)->delete();
            } else {
                $id = DB::table('annual_procurement_plans')->insertGetId([
                    ...$attributes, 'fiscal_year' => $year, 'created_at' => now(),
                ]);
            }

            foreach (array_chunk($lines, 60) as $chunk) {
                DB::table('app_source_lines')->insert(array_map(fn ($line) => [...$line, 'app_id' => $id], $chunk));
            }

            PlanningService::audit($user, 'annual_procurement_plan', $id, 'built', metadata: ['lines' => count($lines)]);
        }, 3);

        return to_route('procurement.app.index', ['year' => $year])->with('success', 'APP draft built from approved PPMPs.');
    }

    public function decide(Request $request, int $year): RedirectResponse
    {
        $this->validateYear($year);
        $user = $request->user();
        PlanningAccess::roles($user, ['procurement', 'mayor']);
        $data = $request->validate([
            'action' => ['required', 'in:submit,approve,return'],
            'version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'required_if:action,return', 'string', 'max:2000'],
        ]);
        $action = $data['action'];
        PlanningAccess::roles($user, [$action === 'submit' ? 'procurement' : 'mayor']);

        DB::transaction(function () use ($user, $year, $action, $data) {
            $app = DB::table('annual_procurement_plans')->where('fiscal_year', $year)->lockForUpdate()->first();
            abort_unless($app, 404);
            $validState = $action === 'submit'
                ? in_array($app->status, ['draft', 'returned'], true)
                : $app->status === 'submitted';

            if (! $validState || (int) $app->version !== (int) $data['version']) {
                PlanningService::fail('The APP changed. Reload it before continuing.');
            }
            if ($action !== 'return' && ! hash_equals($app->source_hash, $this->hash($this->eligibleLines($year)))) {
                PlanningService::fail('The source plans changed. Return the APP if it is awaiting approval, then ask BAC to rebuild it.');
            }

            $status = match ($action) {
                'submit' => 'submitted',
                'approve' => 'approved',
                'return' => 'returned',
            };
            DB::table('annual_procurement_plans')->where('id', $app->id)->update([
                'status' => $status, 'version' => (int) $app->version + 1,
                'review_note' => $action === 'return' ? $data['comment'] : null, 'updated_at' => now(),
            ]);
            PlanningService::audit($user, 'annual_procurement_plan', $app->id, $status, comment: $data['comment'] ?? null);
        }, 3);

        return to_route('procurement.app.index', ['year' => $year])->with('success', 'APP '.match ($action) {
            'submit' => 'submitted to the Mayor.',
            'approve' => 'approved and available for purchase requests.',
            'return' => 'returned to BAC.',
        });
    }

    private function eligibleLines(int $year): array
    {
        $items = PlanItem::query()
            ->join('plans as sheet', 'sheet.id', '=', 'plan_items.plan_id')
            ->join('plans as activity', 'activity.id', '=', 'sheet.parent_id')
            ->join('plans as heading', 'heading.id', '=', 'activity.parent_ppa_id')
            ->join('aip_documents as aip', 'aip.id', '=', 'activity.aip_document_id')
            ->where('sheet.type', 'ppmp')->where('sheet.status', 'approved')->where('sheet.fiscal_year', $year)
            ->where('activity.type', 'aip')->where('heading.type', 'program')->where('aip.review_status', 'attested')
            ->whereColumn('activity.office_id', 'sheet.office_id')->whereColumn('activity.fiscal_year', 'sheet.fiscal_year')
            ->whereColumn('heading.office_id', 'sheet.office_id')->whereColumn('heading.fiscal_year', 'sheet.fiscal_year')
            ->whereColumn('heading.aip_document_id', 'aip.id')
            ->whereColumn('aip.office_id', 'sheet.office_id')->whereColumn('aip.fiscal_year', 'sheet.fiscal_year')
            ->orderBy('plan_items.id')
            ->get(['plan_items.*', 'sheet.office_id', 'sheet.reference as ppmp_reference']);

        $lines = [];
        foreach ($items as $item) {
            $quantities = count($item->monthly_quantities ?? []) === 12
                ? array_map('array_sum', array_chunk($item->monthly_quantities, 3))
                : ($item->quarterly_quantities ?? []);
            if (count($quantities) !== 4 || array_sum($quantities) !== $item->quantity) {
                PlanningService::fail('A saved PPMP item has an incomplete quarterly schedule.');
            }

            foreach ($quantities as $index => $quantity) {
                if ($quantity > 0) {
                    $lines[] = [
                        'office_id' => (int) $item->office_id, 'plan_item_id' => $item->id,
                        ...$item->only(['ppmp_reference', 'code', 'name', 'specification', 'unit', 'expense_class', 'price_cents']),
                        'quarter' => $index + 1, 'quantity' => (int) $quantity,
                    ];
                }
            }
        }

        return $lines;
    }

    private function hash(array $lines): string
    {
        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }

    private function validateYear(int $year): void
    {
        abort_unless($year >= 2020 && $year <= 2100, 404);
    }
}
