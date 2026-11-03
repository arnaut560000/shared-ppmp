<?php

namespace Tests\Feature;

use Tests\PpaTestCase;

class SubPpaAllocationTest extends PpaTestCase
{
    public function test_sibling_total_is_limited_and_exact_remaining_allocation_is_allowed(): void
    {
        $this->child();
        $this->post('/aips', $this->childData(['reference' => 'SECOND', 'mooe' => '4000.01']))
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('plans', 2);
        $this->child(['reference' => 'SECOND', 'mooe' => '4000']);
        $this->assertSame(1000000, (int) $this->main->subPpas()->sum('mooe_cents'));
    }

    public function test_each_expense_class_is_validated_independently(): void
    {
        foreach (['ps' => '1000.01', 'mooe' => '10000.01', 'fe' => '100.01', 'co' => '2000.01'] as $field => $amount) {
            $this->post('/aips', $this->childData([$field => $amount]))->assertSessionHasErrors('workflow');
        }
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_edit_excludes_old_child_amount_but_cannot_reduce_main_below_children(): void
    {
        $child = $this->child();
        $this->put('/aips/'.$child->id, $this->childData(['version' => 1, 'mooe' => '9000']))
            ->assertSessionHasNoErrors();
        $this->assertSame(900000, $child->fresh()->mooe_cents);
        $this->put('/aips/'.$this->main->id, $this->data(['version' => 1, 'mooe' => '8999.99']))
            ->assertSessionHasErrors('workflow');
        $this->assertSame(1000000, $this->main->fresh()->mooe_cents);
        $this->put('/aips/'.$this->main->id, $this->data(['version' => 1, 'mooe' => '9000']))
            ->assertSessionHasNoErrors();
    }
}
