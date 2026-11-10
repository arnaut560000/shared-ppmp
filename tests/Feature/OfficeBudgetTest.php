<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class OfficeBudgetTest extends PpaTestCase
{
    private function allocation(array $changes = []): array
    {
        return array_replace([
            'office_id' => $this->planner->office_id, 'fiscal_year' => 2027,
            'amount' => '15000', 'expected_ceiling' => 1000000,
        ], $changes);
    }

    public function test_budget_officer_can_assign_update_and_audit_yearly_allocations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'budget']));
        $this->post('/budgets', $this->allocation())->assertSessionHasNoErrors()->assertRedirect('/budgets?year=2027');
        $this->assertSame(1500000, Budget::sole()->ceiling_cents);
        $this->assertSame(1000000, AuditEvent::where('subject_type', 'budget')->sole()->metadata['previous_cents']);
        $this->post('/budgets', $this->allocation(['fiscal_year' => 2028, 'expected_ceiling' => null, 'amount' => '500000']))
            ->assertSessionHasNoErrors();
        $this->get('/budgets?year=2028')->assertInertia(fn (Assert $p) => $p
            ->component('office-budgets')->where('offices.0.ceiling_cents', 50000000)
            ->where('offices.0.spent_cents', 0)->where('offices.0.remaining_cents', 50000000));
    }

    public function test_budget_reduction_and_stale_updates_cannot_overwrite_allocations(): void
    {
        $this->child(['ps' => '6000']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/budgets', $this->allocation(['amount' => '5999.99']))->assertSessionHasErrors('workflow');
        $this->post('/budgets', $this->allocation(['expected_ceiling' => null]))->assertSessionHasErrors('workflow');
        $this->assertSame(1000000, Budget::sole()->ceiling_cents);
        $this->post('/budgets', $this->allocation(['amount' => '6000']))->assertSessionHasNoErrors();
        $this->get('/budgets?year=2027')->assertInertia(fn (Assert $p) => $p->where('offices.0.remaining_cents', 0));
        $this->post('/budgets', $this->allocation())->assertSessionHasErrors('workflow');
    }

    public function test_department_and_other_reviewers_cannot_manage_budgets(): void
    {
        foreach ([$this->planner, User::factory()->create(['role' => 'mayor'])] as $user) {
            $this->actingAs($user)->get('/budgets')->assertForbidden();
            $this->post('/budgets', $this->allocation())->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'budget']));
        foreach (['-1', '1.001', '10000000000.01'] as $amount) {
            $this->post('/budgets', $this->allocation(['amount' => $amount]))->assertSessionHasErrors('amount');
        }
        $this->post('/budgets', $this->allocation(['fiscal_year' => 2101]))->assertSessionHasErrors('fiscal_year');
        $this->assertSame(1000000, Budget::sole()->ceiling_cents);
    }

    public function test_heading_legacy_amount_is_excluded_from_office_and_program_totals(): void
    {
        $this->main->update(['mooe_cents' => 50000000, 'total_cents' => 50000000]);
        $this->child(['fe' => '5000']);
        $this->get('/aips?year=2027')->assertInertia(fn (Assert $p) => $p
            ->where('budget.spent_cents', 500000)->where('budget.remaining_cents', 500000)
            ->where('plans.data.0.total_cents', 500000)->where('plans.data.0.legacy_total_cents', 50000000));
    }
}
