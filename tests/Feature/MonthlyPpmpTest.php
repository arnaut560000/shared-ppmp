<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Plan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class MonthlyPpmpTest extends PpaTestCase
{
    private Plan $ppa;

    private CatalogueItem $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ppa = $this->child(['mooe' => '1500']);
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

    public function test_draft_months_roll_up_and_reopen_without_charging_office_twice(): void
    {
        $this->post($this->url(), $this->schedulePayload())->assertSessionHasNoErrors();
        $sheet = Plan::where('type', 'ppmp')->sole();
        $line = $sheet->items()->sole();
        $this->assertSame([5, 0, 0, 1], $line->quarterly_quantities);
        $this->assertSame($this->schedulePayload()['items'][0]['monthly_quantities'], $line->monthly_quantities);
        $this->assertSame(150000, $sheet->total_cents);
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->component('ppmp')
            ->where('selected.id', $sheet->id)->where('canEdit', true)->where('available.MOOE', 150000));
        $this->get($this->url().'?new=1')->assertInertia(fn (Assert $p) => $p->where('available.MOOE', 0));
        $this->get('/aips?year=2027')->assertInertia(fn (Assert $p) => $p->where('budget.allocated_cents', 150000));
        $this->post($this->url(), [...$this->schedulePayload(), 'reference' => 'PL-002'])->assertSessionHasErrors('workflow');
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
