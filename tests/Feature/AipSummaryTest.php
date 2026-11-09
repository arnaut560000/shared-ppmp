<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class AipSummaryTest extends PpaTestCase
{
    public function test_summary_includes_all_programs_and_separates_requirements_from_allocations(): void
    {
        $ppa = $this->child(['performance_indicator' => 'Number of publications', 'physical_target' => '24 publications']);
        for ($i = 0; $i < 15; $i++) {
            $this->post('/aips', $this->data(['reference' => 'P-'.$i]))->assertSessionHasNoErrors();
        }
        $item = CatalogueItem::create(['code' => 'PAPER', 'name' => 'Paper', 'unit' => 'ream', 'category' => 'Supplies',
            'expense_class' => 'MOOE', 'price_cents' => 25000]);
        $this->post('/aips/'.$ppa->id.'/ppmp', ['reference' => 'PL-1', 'items' => [
            ['catalogue_item_id' => $item->id, 'expected_price_cents' => 25000, 'monthly_quantities' => [2, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]],
        ]])->assertSessionHasNoErrors();
        $url = '/aips/documents/'.$ppa->aip_document_id.'/summary';
        $this->get($url)->assertInertia(fn (Assert $p) => $p->component('aip-summary')->has('programs', 16)
            ->where('ceiling', 1000000)->where('programs.0.ppas.0.total_cents', 600000)
            ->where('programs.0.ppas.0.procurement_cents', 125000)->where('programs.0.ppas.0.item_count', 1)
            ->where('programs.0.ppas.0.performance_indicator', 'Number of publications')
            ->where('programs.0.ppas.0.physical_target', '24 publications'));
        $this->actingAs(User::factory()->create(['role' => 'budget']))->get($url)->assertOk();
        $office = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $this->actingAs(User::factory()->create(['role' => 'office', 'office_id' => $office->id]))->get($url)->assertForbidden();
    }
}
