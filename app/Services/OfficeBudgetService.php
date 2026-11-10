<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Plan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OfficeBudgetService
{
    private static function itemLines(int $officeId, int $year): Builder
    {
        return DB::table('plan_items as item')
            ->join('plans as sheet', 'sheet.id', '=', 'item.plan_id')
            ->where('sheet.type', 'ppmp')
            ->where('sheet.office_id', $officeId)
            ->where('sheet.fiscal_year', $year);
    }

    public static function summary(int $officeId, int $year, ?int $excludePpmpId = null): array
    {
        $ceiling = Budget::where('office_id', $officeId)->where('fiscal_year', $year)->select('ceiling_cents');
        $manual = Plan::where('type', 'aip')->where('office_id', $officeId)->where('fiscal_year', $year)
            ->selectRaw('COALESCE(SUM(ps_cents + fe_cents), 0)');
        $items = self::itemLines($officeId, $year)
            ->when($excludePpmpId !== null, fn ($q) => $q->where('sheet.id', '!=', $excludePpmpId))
            ->selectRaw('COALESCE(SUM(item.total_cents), 0)');
        $row = DB::query()->selectSub($ceiling, 'ceiling')->selectSub($manual, 'manual')
            ->selectSub($items, 'procurement')->first();
        $spent = (int) $row->manual + (int) $row->procurement;

        return [
            'ceiling_cents' => $row->ceiling === null ? null : (int) $row->ceiling,
            'spent_cents' => $spent,
            'remaining_cents' => $row->ceiling === null ? null : (int) $row->ceiling - $spent,
        ];
    }

    public static function procurementByPpa(int $officeId, int $year, ?int $ppaId = null): Collection
    {
        return self::itemLines($officeId, $year)
            ->when($ppaId !== null, fn ($q) => $q->where('sheet.parent_id', $ppaId))
            ->select('sheet.parent_id')->selectRaw('COUNT(*) as item_count')
            ->selectRaw('SUM(CASE WHEN item.expense_class = ? THEN item.total_cents ELSE 0 END) as mooe', ['MOOE'])
            ->selectRaw('SUM(CASE WHEN item.expense_class = ? THEN item.total_cents ELSE 0 END) as co', ['CO'])
            ->groupBy('sheet.parent_id')->get()->keyBy('parent_id');
    }

    public static function ppaTotal(Plan $ppa, Collection $procurement): int
    {
        $costs = $procurement->get($ppa->id);

        return $ppa->ps_cents + $ppa->fe_cents + (int) ($costs->mooe ?? 0) + (int) ($costs->co ?? 0);
    }

    public static function requireAvailable(array $budget, int $requested, int $replacedManual = 0): void
    {
        if ($budget['remaining_cents'] === null) {
            PlanningService::fail('The Budget Office must assign an office budget for this fiscal year first.');
        }

        $available = $budget['remaining_cents'] + $replacedManual;
        if ($requested > $available) {
            PlanningService::fail('This would exceed the office budget. Available for this save: PHP '
                .number_format($available / 100, 2).'.');
        }
    }
}
