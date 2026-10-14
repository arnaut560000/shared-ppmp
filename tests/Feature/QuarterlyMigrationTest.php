<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuarterlyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_legacy_months_totals_and_approval(): void
    {
        $migration = require database_path('migrations/2026_10_13_000001_add_quarterly_ppmp_fields.php');
        $migration->down();
        $office = Office::create(['code' => 'LEGACY', 'name' => 'Legacy office']);
        $user = User::factory()->create(['role' => 'office', 'office_id' => $office->id]);
        $catalogue = CatalogueItem::create(['code' => 'LEGACY-1', 'name' => 'Paper', 'unit' => 'ream', 'category' => 'Current category', 'expense_class' => 'MOOE', 'price_cents' => 100]);
        $plan = Plan::create(['type' => 'ppmp', 'office_id' => $office->id, 'created_by' => $user->id, 'fiscal_year' => 2027, 'reference' => 'OLD-1', 'title' => 'Old plan', 'fund_source' => 'General Fund', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'approved', 'version' => 3, 'total_cents' => 7800, 'mooe_cents' => 7800]);
        $item = $plan->items()->create(['catalogue_item_id' => $catalogue->id, 'code' => 'LEGACY-1', 'name' => 'Paper', 'unit' => 'ream', 'expense_class' => 'MOOE', 'price_cents' => 100, 'monthly_quantities' => range(1, 12), 'quantity' => 78, 'total_cents' => 7800]);
        $migration->up();
        $item->refresh();
        $this->assertSame([6, 15, 24, 33], $item->quarterly_quantities);
        $this->assertSame(range(1, 12), $item->monthly_quantities);
        $this->assertSame(7800, $item->total_cents);
        $this->assertNull($item->object_of_expenditure);
        $this->assertSame('approved', $plan->fresh()->status);
        $this->assertSame(3, $plan->fresh()->version);
    }
}
