<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class MonthlyPpmpTest extends PpaTestCase
{
    private Plan $ppa;

    private CatalogueItem $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ppa = $this->child();
        $this->paper = CatalogueItem::create(['code' => 'A4', 'name' => 'Paper', 'unit' => 'ream', 'category' => 'Supplies',
            'expense_class' => 'MOOE', 'price_cents' => 25000, 'active' => true]);
    }

    private function url(): string
    {
        return '/aips/'.$this->ppa->id.'/ppmp';
    }

    private function schedulePayload(): array
    {
        return ['reference' => 'PL-001', 'items' => [['catalogue_item_id' => $this->paper->id, 'expected_price_cents' => 25000,
            'monthly_quantities' => [2, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1]]]];
    }

    public function test_department_and_gso_review_the_same_monthly_sheet(): void
    {
        $this->post($this->url(), $this->schedulePayload())->assertSessionHasNoErrors();
        $sheet = $this->ppa->procurementLists()->sole();
        $review = $this->url().'/'.$sheet->id.'/review';
        $queue = '/ppmp-reviews?year=2027';
        $gso = User::factory()->create(['role' => 'gso']);
        $send = ['action' => 'submit_to_gso', 'version' => 1];
        $return = ['action' => 'return_from_gso', 'version' => 2];
        $this->get($queue)->assertForbidden();
        $other = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $this->actingAs(User::factory()->create(['role' => 'office', 'office_id' => $other->id]))
            ->post($review, $send)->assertForbidden();
        $this->actingAs($gso)->post($review, $send)->assertForbidden();
        $this->get($queue)->assertInertia(fn (Assert $page) => $page->where('sheets.total', 0));

        $this->actingAs($this->planner)->post($review, $send)->assertSessionHasNoErrors();
        $this->assertSame('pending_gso', $sheet->fresh()->status);
        $this->put($this->url().'/'.$sheet->id, [])->assertStatus(409);
        $this->post($review, [...$return, 'comment' => 'Revise quantities'])->assertForbidden();
        $this->actingAs($gso)->get($queue)->assertInertia(fn (Assert $page) => $page
            ->where('reviewQueue', true)->where('sheets.data.0.office_name', 'ICT Office')->where('sheets.data.0.id', $sheet->id));
        $this->get('/ppmp-reviews?year=2026')->assertInertia(fn (Assert $page) => $page->where('sheets.total', 0));
        $this->post($review, $return)->assertSessionHasErrors('comment');
        $this->post($review, [...$return, 'comment' => 'Revise quantities'])->assertSessionHasNoErrors();
        $this->get($queue)->assertInertia(fn (Assert $page) => $page->where('sheets.total', 0));
        $this->actingAs($this->planner)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)->where('reviewHistory.0.comment', 'Revise quantities'));
        $data = $this->schedulePayload();
        $data['items'][0]['monthly_quantities'][0] = 1;
        $this->put($this->url().'/'.$sheet->id, [...$data, 'version' => 3])->assertSessionHasNoErrors();
        $this->post($review, [...$send, 'version' => 4])->assertSessionHasNoErrors();
        $this->actingAs($gso)->post($review, ['action' => 'complete_gso_review', 'version' => 2])->assertSessionHasErrors('workflow');
        $this->post($review, ['action' => 'complete_gso_review', 'version' => 5])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $sheet->fresh()->status);
        $this->assertNull($sheet->fresh()->approved_at);
        $this->get($queue)->assertInertia(fn (Assert $page) => $page->where('sheets.total', 0));
        $this->actingAs($this->planner)->get('/aips?year=2027')->assertInertia(fn (Assert $page) => $page->where('budget.spent_cents', 125000));
    }

    public function test_my_ppmps_lists_saved_sheets_by_office_and_year(): void
    {
        $this->post($this->url(), $this->schedulePayload())->assertSessionHasNoErrors();
        $sheet = $this->ppa->procurementLists()->sole();
        $colleague = User::factory()->create(['role' => 'office', 'office_id' => $this->planner->office_id]);

        $this->actingAs($colleague)->get('/my-ppmps?year=2027')->assertInertia(fn (Assert $page) => $page
            ->component('my-ppmps')->where('office', 'ICT Office')->where('year', 2027)
            ->where('sheets.total', 1)->where('sheets.data.0.id', $sheet->id)
            ->where('sheets.data.0.reference', 'PL-001')->where('sheets.data.0.status', 'draft')
            ->where('sheets.data.0.total_cents', 150000)->where('sheets.data.0.activity_id', $this->ppa->id)
            ->where('sheets.data.0.activity_title', $this->ppa->title)->where('sheets.data.0.heading_title', $this->main->title));
        $this->get($this->url().'?sheet='.$sheet->id)->assertInertia(fn (Assert $page) => $page->where('selected.id', $sheet->id));
        $this->get('/my-ppmps?year=2026')->assertInertia(fn (Assert $page) => $page->where('sheets.total', 0));

        $otherOffice = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $otherUser = User::factory()->create(['role' => 'office', 'office_id' => $otherOffice->id]);
        $this->actingAs($otherUser)->get('/my-ppmps?year=2027&office_id='.$this->planner->office_id)
            ->assertInertia(fn (Assert $page) => $page->where('office', 'Engineering')->where('sheets.total', 0));
        $this->actingAs(User::factory()->create(['role' => 'office', 'office_id' => null]))->get('/my-ppmps')->assertForbidden();
    }

    public function test_draft_months_roll_up_and_reopen_without_charging_office_twice(): void
    {
        $this->post($this->url(), $this->schedulePayload())->assertSessionHasNoErrors();
        $sheet = Plan::where('type', 'ppmp')->sole();
        $line = $sheet->items()->sole();
        $this->assertSame([5, 0, 0, 1], $line->quarterly_quantities);
        $this->assertSame($this->schedulePayload()['items'][0]['monthly_quantities'], $line->monthly_quantities);
        $this->assertSame(150000, $sheet->total_cents);
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->component('ppmp')
            ->where('selected.id', $sheet->id)->where('canEdit', true)->where('available_for_sheet_cents', 1000000));
        $this->get($this->url().'?new=1')->assertInertia(fn (Assert $p) => $p->where('available_for_sheet_cents', 850000));
        $this->get('/aips?year=2027')->assertInertia(fn (Assert $p) => $p->where('budget.spent_cents', 150000));
        $this->post($this->url(), [...$this->schedulePayload(), 'reference' => 'PL-002'])->assertSessionHasNoErrors();
        $this->post($this->url(), $this->schedulePayload())->assertSessionHasErrors('reference');
    }

    public function test_edits_preserve_saved_price_and_refuse_stale_versions(): void
    {
        $data = $this->schedulePayload();
        $this->post($this->url(), $data)->assertSessionHasNoErrors();
        $sheet = Plan::where('type', 'ppmp')->sole();
        $this->paper->update(['active' => false, 'price_cents' => 40000, 'name' => 'Updated paper']);
        $data['items'][0]['monthly_quantities'][0] = 1;
        $this->put($this->url().'/'.$sheet->id, [...$data, 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(125000, $sheet->fresh()->total_cents);
        $this->assertSame('Paper', $sheet->items()->sole()->name);
        $this->put($this->url().'/'.$sheet->id, [...$data, 'version' => 1])->assertSessionHasErrors('workflow');
        $this->assertSame(2, $sheet->fresh()->version);
    }

    public function test_invalid_dates_of_demand_duplicates_and_price_changes_are_rejected(): void
    {
        $data = $this->schedulePayload();
        $data['items'][0]['monthly_quantities'] = [1, 2, 3, 4];
        $this->post($this->url(), $data)->assertSessionHasErrors('items.0.monthly_quantities');
        $data = $this->schedulePayload();
        $data['items'][] = $data['items'][0];
        $this->post($this->url(), $data)->assertSessionHasErrors('items.0.catalogue_item_id');
        $data = $this->schedulePayload();
        $data['items'][0]['monthly_quantities'][0] = -1;
        $this->post($this->url(), $data)->assertSessionHasErrors('items.0.monthly_quantities.0');
        $data = $this->schedulePayload();
        $data['items'][0]['monthly_quantities'] = array_fill(0, 12, 0);
        $this->post($this->url(), $data)->assertSessionHasErrors('workflow');
        $this->paper->update(['price_cents' => 30000]);
        $this->post($this->url(), $this->schedulePayload())->assertSessionHasErrors('items');
        $this->assertSame(0,Plan::where('type','ppmp')->count());
    }
}
