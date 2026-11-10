<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Plan;
use Tests\PpaTestCase;

class ProgramPpaAllocationTest extends PpaTestCase
{
    public function test_exact_office_balance_is_allowed_and_one_cent_over_is_rejected(): void
    {
        $this->child(['ps' => '6000']);
        $this->post('/aips', $this->childData(['reference' => 'SECOND', 'fe' => '4000.01']))
            ->assertSessionHasErrors('workflow');
        $this->child(['reference' => 'SECOND', 'fe' => '4000']);
        $this->assertSame(1000000, (int) $this->main->subPpas()->sum('total_cents'));
    }

    public function test_manual_categories_use_office_budget_independently_of_headings(): void
    {
        $this->assertSame(0, $this->main->total_cents);
        foreach (['ps', 'fe'] as $field) {
            $this->child(['reference' => strtoupper($field), $field => '5000']);
        }
        $this->assertSame(1000000, (int) $this->main->subPpas()->sum('total_cents'));
    }

    public function test_edit_excludes_old_allocation_and_headings_cannot_set_a_budget(): void
    {
        $child = $this->child(['ps' => '6000']);
        $this->put('/aips/'.$child->id, $this->childData(['version' => 1, 'ps' => '9000']))->assertSessionHasNoErrors();
        $this->assertSame(900000, $child->fresh()->total_cents);
        $this->put('/aips/'.$this->main->id, $this->data(['version' => 1, 'mooe' => '1', 'title' => 'Renamed program']))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $this->main->fresh()->total_cents);
        $this->assertSame('Renamed program', $this->main->fresh()->title);
    }

    public function test_budget_is_shared_across_programs_but_isolated_by_year(): void
    {
        $this->child(['ps' => '6000']);
        $this->post('/aips', $this->data(['reference' => 'OTHER']))->assertSessionHasNoErrors();
        $other = Plan::where('reference', 'OTHER')->sole();
        $this->post('/aips', $this->childData(['parent_ppa_id' => $other->id, 'reference' => 'OTHER-PPA', 'fe' => '4000.01']))
            ->assertSessionHasErrors('workflow');
        $this->post('/aips', $this->childData(['parent_ppa_id' => $other->id, 'reference' => 'OTHER-PPA', 'fe' => '4000']))
            ->assertSessionHasNoErrors();
    }

    public function test_heading_can_exist_without_budget_but_ppa_cannot(): void
    {
        Budget::query()->delete();
        $this->post('/aips', $this->data(['reference' => 'NO-BUDGET']))->assertSessionHasNoErrors();
        $this->post('/aips', $this->childData())->assertSessionHasErrors('workflow');
        $this->assertSame(0, Plan::where('type', 'aip')->count());
    }
}
