<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class OfficeBudgetSpendingTest extends PpaTestCase
{
    private CatalogueItem $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paper = $this->catalogueItem('Paper', 25000, 'MOOE');
    }

    private function catalogueItem(string $name, int $priceCents, string $class): CatalogueItem
    {
        return CatalogueItem::create([
            'code' => strtoupper($name), 'name' => $name, 'unit' => 'unit', 'category' => 'Test items',
            'expense_class' => $class, 'price_cents' => $priceCents, 'active' => true,
        ]);
    }

    private function line(CatalogueItem $item, int $january, int $december = 0): array
    {
        $months = array_fill(0, 12, 0);
        $months[0] = $january;
        $months[11] = $december;

        return [
            'catalogue_item_id' => $item->id, 'expected_price_cents' => $item->price_cents,
            'monthly_quantities' => $months,
        ];
    }

    private function saveList(Plan $ppa, string $reference, array $items): Plan
    {
        $this->post(route('ppmp.store', $ppa), compact('reference', 'items'))
            ->assertRedirect()->assertSessionHasNoErrors();

        return $ppa->procurementLists()->where('reference', $reference)->sole();
    }

    private function assertBudget(Plan $ppa, int $spent, ?int $remaining): void
    {
        $this->get(route('ppmp.show', ['ppa' => $ppa, 'new' => 1]))
            ->assertInertia(fn (Assert $page) => $page->component('ppmp')
                ->where('budget.spent_cents', $spent)->where('budget.remaining_cents', $remaining)
                ->where('available_for_sheet_cents', $remaining));
    }

    public function test_saving_items_in_one_ppa_lowers_the_balance_available_to_another(): void
    {
        $first = $this->child();
        $second = $this->child(['reference' => 'PPA-B']);
        $this->assertBudget($second, 0, 1000000);

        $this->saveList($first, 'SUPPLIES', [$this->line($this->paper, 2, 3)]);
        $this->assertBudget($second, 125000, 875000);
    }

    public function test_a_save_one_cent_over_the_shared_ceiling_is_rejected_without_changing_saved_lines(): void
    {
        $first = $this->child();
        $second = $this->child(['reference' => 'PPA-B']);
        $saved = $this->saveList($first, 'SUPPLIES', [$this->line($this->paper, 5)]);
        $line = $saved->items()->sole();
        $equipment = $this->catalogueItem('Equipment', 875001, 'CO');

        $this->post(route('ppmp.store', $second), [
            'reference' => 'OVER', 'items' => [$this->line($equipment, 1)],
        ])->assertSessionHasErrors('workflow');
        $this->assertStringContainsString('8,750.00', session('errors')->first('workflow'));

        $this->assertDatabaseMissing('plans', ['parent_id' => $second->id, 'reference' => 'OVER']);
        $this->assertDatabaseHas('plan_items', ['id' => $line->id, 'plan_id' => $saved->id, 'total_cents' => 125000]);
        $this->assertBudget($second, 125000, 875000);
        $equipment->update(['price_cents' => 875000]);
        $this->saveList($second, 'EXACT', [$this->line($equipment, 1)]);
        $this->assertBudget($first, 1000000, 0);
    }

    public function test_editing_excludes_only_the_sheet_being_replaced(): void
    {
        $ppa = $this->child();
        $sheet = $this->saveList($ppa, 'FIRST', [$this->line($this->paper, 28)]);
        $this->saveList($ppa, 'SECOND', [$this->line($this->paper, 8)]);
        $this->assertBudget($ppa, 900000, 100000);
        $url = route('ppmp.update', ['ppa' => $ppa, 'ppmp' => $sheet]);

        $this->put($url, ['reference' => 'FIRST', 'version' => 1, 'items' => [$this->line($this->paper, 33)]])
            ->assertSessionHasErrors('workflow');
        $this->assertSame(1, $sheet->fresh()->version);
        $this->assertSame(700000, $sheet->items()->sole()->total_cents);
        $this->put($url, ['reference' => 'FIRST', 'version' => 1, 'items' => [$this->line($this->paper, 32)]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertBudget($ppa, 1000000, 0);
    }

    public function test_offices_have_independent_spending_in_the_same_year(): void
    {
        $ict = $this->child();
        $this->saveList($ict, 'SUPPLIES', [$this->line($this->paper, 5)]);

        $other = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $this->actingAs(User::factory()->create(['role' => 'office', 'office_id' => $other->id]));
        Budget::create(['office_id' => $other->id, 'fiscal_year' => 2027, 'ceiling_cents' => 50000]);
        $this->post('/aips', $this->data(['office_id' => $other->id, 'reference' => 'ENG-PROGRAM']))
            ->assertSessionHasNoErrors();
        $program = Plan::where('office_id', $other->id)->where('type', 'program')->sole();
        $engineering = $this->child([
            'office_id' => $other->id, 'parent_ppa_id' => $program->id,
            'aip_document_id' => $program->aip_document_id, 'reference' => 'ENG-PPA',
        ]);
        $this->assertBudget($engineering, 0, 50000);
        $this->saveList($engineering, 'SUPPLIES', [$this->line($this->paper, 2)]);
        $this->assertBudget($engineering, 50000, 0);

        $this->actingAs($this->planner);
        $this->assertBudget($ict, 125000, 875000);
    }

    public function test_ceiling_cannot_be_lowered_below_procurement_plus_ps_and_fe(): void
    {
        $ppa = $this->child(['ps' => '200', 'fe' => '50']);
        $this->saveList($ppa, 'SUPPLIES', [$this->line($this->paper, 5)]);
        $budget = Budget::sole();
        $this->actingAs(User::factory()->create(['role' => 'budget']));
        $form = ['office_id' => $ppa->office_id, 'fiscal_year' => 2027, 'expected_ceiling' => 1000000];

        $this->post('/budgets', [...$form, 'amount' => '1499.99'])->assertSessionHasErrors('workflow');
        $this->assertSame(1000000, $budget->fresh()->ceiling_cents);
        $this->post('/budgets', [...$form, 'amount' => '1500.00'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertBudget($ppa, 150000, 0);
    }

    public function test_removing_a_line_and_reducing_a_quantity_each_release_office_funds(): void
    {
        $ppa = $this->child();
        $printer = $this->catalogueItem('Printer', 50000, 'CO');
        $sheet = $this->saveList($ppa, 'MIXED', [$this->line($this->paper, 4), $this->line($printer, 1)]);
        $this->assertBudget($ppa, 150000, 850000);
        $url = route('ppmp.update', ['ppa' => $ppa, 'ppmp' => $sheet]);

        $this->put($url, ['reference' => 'MIXED', 'version' => 1, 'items' => [$this->line($this->paper, 4)]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('plan_items', ['plan_id' => $sheet->id, 'catalogue_item_id' => $printer->id]);
        $this->assertBudget($ppa, 100000, 900000);
        $this->put($url, ['reference' => 'MIXED', 'version' => 2, 'items' => [$this->line($this->paper, 1)]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertBudget($ppa, 25000, 975000);
    }

    public function test_a_budget_for_another_year_cannot_fund_a_ppmp_with_no_budget_this_year(): void
    {
        $ppa = $this->child();
        Budget::sole()->update(['fiscal_year' => 2028]);

        $this->post(route('ppmp.store', $ppa), [
            'reference' => 'UNFUNDED', 'items' => [$this->line($this->paper, 1)],
        ])->assertSessionHasErrors('workflow');
        $this->assertStringContainsString('assign an office budget', session('errors')->first('workflow'));
        $this->assertDatabaseMissing('plans', ['parent_id' => $ppa->id, 'type' => 'ppmp']);
        $this->assertDatabaseCount('plan_items', 0);
        $this->assertBudget($ppa, 0, null);
    }
}
