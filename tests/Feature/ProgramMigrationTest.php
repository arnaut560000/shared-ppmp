<?php

namespace Tests\Feature;

use App\Models\Plan;
use Tests\PpaTestCase;

class ProgramMigrationTest extends PpaTestCase
{
    public function test_conversion_only_changes_heading_type_and_preserves_activities_and_amounts(): void
    {
        $child = $this->child();
        $child->update(['mooe_cents' => 600000, 'total_cents' => 600000]);
        $this->main->update(['type' => 'aip', 'mooe_cents' => 50000000, 'total_cents' => 50000000, 'status' => 'approved']);
        $before = $this->main->fresh()->getAttributes();
        $childBefore = $child->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_03_000001_convert_main_ppas_to_program_headings.php');
        $migration->up();
        $this->assertSame([...$before, 'type' => 'program'], $this->main->fresh()->getAttributes());
        $this->assertSame($childBefore, $child->fresh()->getAttributes());
        $this->assertSame(600000, (int) $this->main->aipDocument->ppas()->sum('total_cents'));
    }

    public function test_conversion_refuses_to_strand_existing_procurement_parent(): void
    {
        $this->main->update(['type' => 'aip']);
        Plan::create([
            'type' => 'ppmp', 'office_id' => $this->main->office_id, 'fiscal_year' => 2027,
            'reference' => 'EXISTING-PPMP', 'title' => 'Existing procurement', 'fund_source' => 'GF',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
            'created_by' => $this->planner->id, 'parent_id' => $this->main->id,
        ]);
        $migration = require database_path('migrations/2026_11_03_000001_convert_main_ppas_to_program_headings.php');
        try {
            $migration->up();
            $this->fail('Linked parent was converted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PPMP', $e->getMessage());
        }
        $this->assertSame('aip', $this->main->fresh()->type);
    }
}
