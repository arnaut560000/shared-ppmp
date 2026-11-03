<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlanningService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class SubPpaFlowTest extends PpaTestCase
{
    public function test_form_inherits_context_and_child_is_nested_without_double_counting(): void
    {
        $this->get('/aips/'.$this->main->id.'/sub-ppas/create')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('ppas')->where('startCreating', true)->where('parentPpa.id', $this->main->id)
            ->where('initial.parent_ppa_id', (string) $this->main->id)->where('initial.reference', '2027-0-1-001')
            ->where('initial.fund_source', 'GF')->where('parentPpa.available.mooe', 1000000));
        $child = $this->child();
        $this->assertSame($this->main->id, $child->parent_ppa_id);
        $this->assertNull($child->parent_id);
        $this->assertSame($this->main->aip_document_id, $child->aip_document_id);
        $this->get('/aips?year=2027')->assertInertia(fn (Assert $p) => $p
            ->has('plans.data', 1)->where('plans.data.0.id', $this->main->id)
            ->where('plans.data.0.children.0.id', $child->id)->where('totals.total', 1310000));
        $this->get('/aips/'.$this->main->id.'/sub-ppas/create')->assertInertia(fn (Assert $p) => $p
            ->where('initial.reference', '2027-0-1-002')->where('parentPpa.available.mooe', 400000));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/aips?year=2027')
            ->assertInertia(fn (Assert $p) => $p->where('documents.data.0.ppas_count', 2)
                ->where('documents.data.0.ppas_sum_total_cents', 1310000));
    }

    public function test_sub_ppa_stays_with_its_parent_on_later_pages_after_saving(): void
    {
        for ($i = 0; $i < 15; $i++) {
            app(PlanningService::class)->save($this->planner, $this->data(['reference' => 'MAIN-'.$i]));
        }
        $this->post('/aips', $this->childData())->assertSessionHasNoErrors()
            ->assertRedirect(route('aips.documents.show', ['document' => $this->main->aip_document_id, 'page' => 2]));
        $this->get('/aips?year=2027&page=2')->assertInertia(fn (Assert $p) => $p
            ->where('plans.total', 16)->has('plans.data', 1)->where('plans.data.0.id', $this->main->id)
            ->has('plans.data.0.children', 1));
    }

    public function test_invalid_duplicate_and_stale_child_saves_preserve_existing_data(): void
    {
        $this->post('/aips', $this->childData(['end_date' => '2028-01-01']))->assertSessionHasErrors('end_date');
        $this->assertDatabaseCount('plans', 1);
        $child = $this->child();
        $this->post('/aips', $this->childData())->assertSessionHasErrors('reference');
        $this->put('/aips/'.$child->id, $this->childData(['version' => 1, 'mooe' => '5000']))->assertSessionHasNoErrors();
        $this->put('/aips/'.$child->id, $this->childData(['version' => 1]))->assertSessionHasErrors('workflow');
        $this->assertSame(500000, $child->fresh()->mooe_cents);
        $this->assertDatabaseCount('plans', 2);
    }

    public function test_hierarchy_migration_preserves_existing_plan_and_refuses_to_flatten_children(): void
    {
        $migration = require database_path('migrations/2026_10_30_000002_add_parent_ppa_id.php');
        $migration->down();
        $before = $this->main->fresh()->getAttributes();
        $migration->up();
        $after = $this->main->fresh()->getAttributes();
        unset($after['parent_ppa_id']);
        $this->assertSame($before, $after);
        $this->child();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
