<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use App\Services\ConsolidatedPpmpReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ConsolidatedPpmpTest extends TestCase
{
    use RefreshDatabase;

    private Office $ict;
    private Office $budget;
    private User $gso;
    private CatalogueItem $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ict = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $this->budget = Office::create(['code' => 'MBO', 'name' => 'Budget Office']);
        $this->gso = User::factory()->create(['role' => 'gso']);
        $this->actingAs($this->gso);
        $this->paper = CatalogueItem::create([
            'code' => '001', 'name' => '=Paper <A4>', 'unit' => 'ream',
            'category' => 'Supplies', 'expense_class' => 'MOOE', 'price_cents' => 99999,
        ]);
        $this->sheet($this->ict, 20000, [10, 0, 0, 0], 'ICT-APPROVED');
        $this->sheet($this->budget, 22000, [0, 5, 0, 0], 'MBO-APPROVED');
        $co = $this->sheet($this->ict, 50000, [0, 0, 0, 2], 'ICT-CO');
        $co->items()->update(['expense_class' => 'CO', 'code' => 'CO-1', 'name' => 'Equipment']);
        $this->sheet($this->ict, 20000, [999, 0, 0, 0], 'DRAFT')->update(['status' => 'draft']);
        $this->sheet($this->ict, 20000, [999, 0, 0, 0], 'PENDING')->update(['status' => 'pending_gso']);
        $this->sheet($this->ict, 20000, [999, 0, 0, 0], 'OLD')->update(['fiscal_year' => 2026]);
    }

    private function sheet(Office $office, int $price, array $quarters, string $reference): Plan
    {
        $attributes = [
            'office_id' => $office->id, 'fiscal_year' => 2027, 'fund_source' => 'GF',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'created_by' => $this->gso->id,
        ];
        $heading = Plan::create($attributes + ['type' => 'program', 'reference' => 'P-'.$reference, 'title' => 'Office program']);
        $activity = Plan::create($attributes + ['type' => 'aip', 'reference' => 'A-'.$reference, 'title' => 'Office activity', 'parent_ppa_id' => $heading->id]);
        $sheet = Plan::create($attributes + [
            'type' => 'ppmp', 'reference' => $reference, 'title' => 'Supplies',
            'status' => 'approved', 'parent_id' => $activity->id,
            'total_cents' => array_sum($quarters) * $price,
        ]);
        $sheet->items()->create([
            'catalogue_item_id' => $this->paper->id,
            'code' => $this->paper->code, 'name' => $this->paper->name,
            'unit' => 'ream', 'expense_class' => 'MOOE', 'object_of_expenditure' => 'Supplies',
            'price_cents' => $price, 'quantity' => array_sum($quarters),
            'quarterly_quantities' => $quarters, 'monthly_quantities' => null,
            'total_cents' => array_sum($quarters) * $price,
        ]);

        return $sheet;
    }

    public function test_approved_rows_merge_prices_and_preserve_source_costs(): void
    {
        $this->paper->update(['price_cents' => 123, 'active' => false]);
        $this->get('/consolidated-ppmp?year=2027')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('consolidated-ppmp')->where('planCount', 3)
            ->where('totalCents', 410000)->has('items', 2)
            ->where('items.0.quantities', [10, 5, 0, 0])
            ->where('items.0.amounts', [200000, 110000, 0, 0])
            ->where('items.0.price_cents', null)
            ->has('items.0.sources', 2)
            ->where('items.0.sources.0.ppa', 'Office program')
            ->where('items.0.sources.0.activity', 'Office activity')
            ->where('classes.MOOE', [200000, 110000, 0, 0])
            ->where('classes.CO', [0, 0, 0, 100000]));
        $legacy = app(ConsolidatedPpmpReport::class)->build($this->gso, 2027);
        $this->assertCount(3, $legacy['rows']);
        $this->assertSame(410000, $legacy['total_cents']);
    }

    public function test_filters_empty_year_and_invalid_filters(): void
    {
        $this->get('/consolidated-ppmp?year=2027&office_id='.$this->budget->id)
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('totalCents', 110000)->where('planCount', 1)->has('items', 1));
        $this->get('/consolidated-ppmp?year=2028')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('totalCents', 0)->has('items', 0));
        $this->getJson('/consolidated-ppmp?year=bad')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/consolidated-ppmp/excel?office_id=999999')->assertUnprocessable()->assertJsonValidationErrors('office_id');
    }

    public function test_both_routes_are_restricted_to_gso_and_admin(): void
    {
        foreach (['office', 'budget', 'mayor', 'procurement', 'inventory', 'mpdc'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'office_id' => $this->ict->id]));
            $this->get('/consolidated-ppmp')->assertForbidden();
            $this->get('/consolidated-ppmp/excel')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/consolidated-ppmp?year=2027')->assertOk();
        auth()->logout();
        $this->get('/consolidated-ppmp')->assertRedirect('/login');
        $this->get('/consolidated-ppmp/excel')->assertRedirect('/login');
    }

    public function test_changed_units_and_specifications_stay_separate(): void
    {
        $different = $this->sheet($this->ict, 20000, [1, 0, 0, 0], 'DIFFERENT');
        $different->items()->update(['unit' => 'box', 'specification' => 'Ten reams']);
        $report = app(ConsolidatedPpmpReport::class)->build($this->gso, 2027, consolidatePrices: true);
        $this->assertCount(3, $report['rows']);
        $this->assertSame(430000, $report['total_cents']);
    }

    public function test_excel_reconciles_with_screen_and_preserves_literal_text(): void
    {
        foreach ([null => 4100, $this->budget->id => 1100] as $officeId => $total) {
            $query = http_build_query(['year' => 2027, 'office_id' => $officeId]);
            $response = $this->get('/consolidated-ppmp/excel?'.$query)->assertOk();
            $file = tempnam(sys_get_temp_dir(), 'consolidated');
            try {
                file_put_contents($file, $response->streamedContent());
                $book = IOFactory::load($file);
                $page = $book->getSheetByName('Consolidated PPMP');
                $this->assertSame('001', $page->getCell('B3')->getValue());
                $this->assertSame('=Paper <A4>', $page->getCell('C3')->getValue());
                $this->assertSame('s', $page->getCell('C3')->getDataType());
                $this->assertEquals($total, $page->getCell('O'.$page->getHighestDataRow())->getValue());
                $sources = $book->getSheetByName('Source sheets');
                $sourceTotal = 0;
                for ($row = 3; $row <= $sources->getHighestDataRow(); $row++) {
                    $sourceTotal += $sources->getCell('R'.$row)->getValue();
                }
                $this->assertEquals($total, $sourceTotal);
                $book->disconnectWorksheets();
            } finally {
                unlink($file);
            }
        }
    }
}

