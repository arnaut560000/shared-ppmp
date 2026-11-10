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

    public function test_editing_details_without_amounts_preserves_existing_manual_expenses(): void
    {
        $ppa = $this->child(['ps' => '125.50', 'fe' => '25']);
        $data = $this->childData(['version' => 1, 'title' => 'Updated activity']);
        unset($data['ps'], $data['fe']);
        $this->put('/aips/'.$ppa->id, $data)->assertSessionHasNoErrors()->assertRedirect();
        $ppa->refresh();
        $this->assertSame('Updated activity', $ppa->title);
        $this->assertSame(12550, $ppa->ps_cents);
        $this->assertSame(2500, $ppa->fe_cents);
        $this->assertSame(15050, $ppa->total_cents);
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

    public function test_unfunded_office_can_create_an_unpriced_ppa_but_cannot_add_expenses(): void
    {
        Budget::query()->delete();
        $this->post('/aips', $this->data(['reference' => 'NO-BUDGET']))->assertSessionHasNoErrors();
        $this->get('/aips/'.$this->main->id.'/ppas/create')->assertInertia(fn ($page) => $page
            ->missing('initial.ps')->missing('initial.fe')->missing('initial.mooe')->missing('initial.co'));
        $data = $this->childData();
        unset($data['ps'], $data['fe']);
        $this->post('/aips', $data)->assertSessionHasNoErrors()->assertRedirect();
        $ppa = Plan::where('type', 'aip')->sole();
        $this->assertSame(0, $ppa->total_cents);
        $this->put('/aips/'.$ppa->id, [...$data, 'version' => 1, 'ps' => '0.01'])->assertSessionHasErrors('workflow');
        $this->assertSame(0, $ppa->fresh()->total_cents);
    }
}
