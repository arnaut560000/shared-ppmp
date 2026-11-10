<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanningService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class PpmpAccessTest extends PpaTestCase
{
    public function test_foreign_departments_and_heading_ids_cannot_open_procurement(): void
    {
        $ppa = $this->child();
        $url = '/aips/'.$ppa->id.'/ppmp';
        $this->get('/aips/'.$this->main->id.'/ppmp')->assertNotFound();
        $office = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $other = User::factory()->create(['role' => 'office', 'office_id' => $office->id]);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->post($url, [])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'budget']))->get($url)
            ->assertInertia(fn (Assert $p) => $p->where('canPrepare', false)->where('canEdit', false));
        $this->post($url, [])->assertForbidden();
    }

    public function test_sheet_binding_and_legacy_quarters_are_protected(): void
    {
        $ppa = $this->child();
        $other = $this->child(['reference' => 'SECOND', 'mooe' => '100']);
        $url = '/aips/'.$ppa->id.'/ppmp';
        $item = CatalogueItem::create(['code' => 'PEN', 'name' => 'Pen', 'unit' => 'pc', 'category' => 'Supplies', 'expense_class' => 'MOOE', 'price_cents' => 100]);
        $sheet = app(PlanningService::class)->save($this->planner, [
            'type' => 'ppmp', 'parent_id' => $ppa->id, 'office_id' => $ppa->office_id, 'fiscal_year' => 2027, 'reference' => 'OLD', 'title' => 'Old quarters',
            'fund_source' => 'GF', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
            'items' => [['catalogue_item_id' => $item->id, 'quarterly_quantities' => [1, 0, 0, 0]]],
        ]);
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where('legacy', true)->where('canEdit', false)
            ->where('selected.items.0.monthly_quantities', null));
        $this->put($url.'/'.$sheet->id, [])->assertStatus(409);
        $this->get('/aips/'.$other->id.'/ppmp?sheet='.$sheet->id)->assertNotFound();
        $this->put('/aips/'.$other->id.'/ppmp/'.$sheet->id, [])->assertNotFound();
        app(PlanningService::class)->transition($this->planner, $sheet, 'submit', 1, null);
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_submitted_ppa_freezes_new_drafts(): void
    {
        $ppa = $this->child();
        $ppa->update(['status' => 'submitted']);
        $item = CatalogueItem::create(['code' => 'PEN', 'name' => 'Pen', 'unit' => 'pc', 'category' => 'Supplies', 'expense_class' => 'MOOE', 'price_cents' => 100]);
        $url = '/aips/'.$ppa->id.'/ppmp';
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where('canPrepare', false));
        $this->post($url, ['reference' => 'LOCKED', 'items' => [['catalogue_item_id' => $item->id, 'expected_price_cents' => 100,
            'monthly_quantities' => array_fill(0, 12, 1)]]])->assertSessionHasErrors('workflow');
        $this->assertSame(0, Plan::where('type','ppmp')->count());
    }
}
