<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use App\Services\PlanningService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class SubPpaAccessTest extends PpaTestCase
{
    public function test_other_departments_and_readers_cannot_create_children(): void
    {
        $office = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        foreach ([User::factory()->create(['role' => 'office', 'office_id' => $office->id]), User::factory()->create(['role' => 'budget'])] as $user) {
            $this->actingAs($user)->get('/aips/'.$this->main->id.'/sub-ppas/create')->assertForbidden();
            $this->post('/aips', $this->childData())->assertForbidden();
        }
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_wrong_year_nested_parent_and_ppmp_parent_are_rejected(): void
    {
        $this->post('/aips', $this->childData(['aip_document_id' => '', 'fiscal_year' => 2028, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31']))
            ->assertSessionHasErrors('workflow');
        $child = $this->child();
        $this->get('/aips/'.$child->id.'/sub-ppas/create')->assertNotFound();
        $this->post('/aips', $this->childData(['parent_ppa_id' => $child->id, 'reference' => 'NESTED']))
            ->assertSessionHasErrors('workflow');
        $this->main->update(['type' => 'ppmp']);
        $this->post('/aips', $this->childData(['reference' => 'WRONG-TYPE']))->assertSessionHasErrors('workflow');
    }

    public function test_parent_is_fixed_on_edit_and_locked_parents_lock_children(): void
    {
        $child = $this->child();
        $other = app(PlanningService::class)->save($this->planner, $this->data(['reference' => 'OTHER']));
        $this->put('/aips/'.$child->id, $this->childData(['version' => 1, 'parent_ppa_id' => $other->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->main->id, $child->fresh()->parent_ppa_id);
        foreach (['submitted', 'approved'] as $status) {
            $this->main->update(['status' => $status]);
            $this->get('/aips/'.$this->main->id.'/sub-ppas/create')->assertStatus(409);
            $this->get('/aips/'.$child->id.'/edit')->assertStatus(409);
            $this->put('/aips/'.$child->id, $this->childData(['version' => 2]))->assertStatus(409);
            $this->get('/aips/'.$child->id.'/view')->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('readOnly', true));
        }
    }

    public function test_sub_ppa_cannot_reserve_a_separate_budget_through_legacy_submission(): void
    {
        $child = $this->child();
        $this->expectException(ValidationException::class);
        app(PlanningService::class)->transition($this->planner, $child, 'submit', 1, null);
    }
}
