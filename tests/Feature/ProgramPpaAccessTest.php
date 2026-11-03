<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanningService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class ProgramPpaAccessTest extends PpaTestCase
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

    public function test_parent_is_fixed_and_only_ppa_approval_locks_its_editing(): void
    {
        $child = $this->child();
        $this->post('/aips', $this->data(['reference' => 'OTHER']))->assertSessionHasNoErrors();
        $other = Plan::where('reference', 'OTHER')->sole();
        $this->put('/aips/'.$child->id, $this->childData(['version' => 1, 'parent_ppa_id' => $other->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->main->id, $child->fresh()->parent_ppa_id);
        foreach (['submitted', 'approved'] as $status) {
            $child->update(['status' => $status]);
            $this->get('/aips/'.$this->main->id.'/sub-ppas/create')->assertOk();
            $this->get('/aips/'.$child->id.'/edit')->assertStatus(409);
            $this->put('/aips/'.$child->id, $this->childData(['version' => 2]))->assertStatus(409);
            $this->get('/aips/'.$child->id.'/view')->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('readOnly', true));
        }
    }

    public function test_actual_ppa_can_be_submitted_but_heading_cannot(): void
    {
        $child = $this->child();
        app(PlanningService::class)->transition($this->planner, $child, 'submit', 1, null);
        $this->assertSame('submitted', $child->fresh()->status);
        $this->expectException(ValidationException::class);
        app(PlanningService::class)->transition($this->planner, $this->main, 'submit', 1, null);
    }
}
