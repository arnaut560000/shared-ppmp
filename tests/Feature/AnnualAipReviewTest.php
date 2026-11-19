<?php

namespace Tests\Feature;

use App\Models\AipDocument;
use App\Models\Office;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\PpaTestCase;

class AnnualAipReviewTest extends PpaTestCase
{
    private function document(): AipDocument
    {
        return AipDocument::findOrFail($this->main->aip_document_id);
    }

    private function decide(User $user, string $action, array $changes = []): TestResponse
    {
        $document = $this->document();
        $path = '/aips/documents/'.$document->id;
        $summary = $this->actingAs($user)->get($path.'/summary')->assertOk();

        return $this->post($path.'/review', $changes + [
            'action' => $action,
            'revision' => $document->revision,
            'summaryToken' => $summary->viewData('page')['props']['summaryToken'],
        ]);
    }

    public function test_only_the_assigned_office_can_submit_its_annual_plan(): void
    {
        $this->child();
        $office = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $outsider = User::factory()->create(['role' => 'office', 'office_id' => $office->id]);
        $this->actingAs($outsider)->post('/aips/documents/'.$this->document()->id.'/review', [
            'action' => 'submit', 'revision' => 1, 'summaryToken' => str_repeat('0', 64),
        ])->assertForbidden();
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->assertSame('submitted', $this->document()->review_status);
    }

    public function test_submission_requires_current_figures_and_locks_activities(): void
    {
        $this->decide($this->planner, 'submit')->assertSessionHasErrors('workflow');
        $this->child();
        $this->decide($this->planner, 'submit', ['summaryToken' => str_repeat('0', 64)])->assertSessionHasErrors('workflow');
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->post('/aips', $this->childData(['reference' => 'EXTRA']))->assertSessionHasErrors('workflow');
    }

    public function test_mpdc_can_return_a_submitted_aip_with_feedback_on_an_activity(): void
    {
        $activity = $this->child();
        $planning = User::factory()->create(['role' => 'mpdc']);
        $this->actingAs($planning)->get('/aips/documents/'.$this->document()->id.'/summary')->assertForbidden();
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->decide($planning, 'return')->assertSessionHasErrors('comment');
        $this->decide($planning, 'return', ['comment' => 'Clarify the target.', 'activity_id' => $activity->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('returned', $this->document()->review_status);
        $this->assertDatabaseHas('audit_events', ['subject_type' => 'aip_review', 'comment' => 'Clarify the target.']);
    }

    public function test_resubmission_restarts_both_reviews(): void
    {
        $this->child();
        $planning = User::factory()->create(['role' => 'mpdc']);
        $budget = User::factory()->create(['role' => 'budget']);
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->decide($planning, 'complete')->assertSessionHasNoErrors();
        $this->decide($budget, 'return', ['comment' => 'Revise the output.'])->assertSessionHasNoErrors();
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->document()->budget_review);
        $this->assertSame('pending', $this->document()->planning_review);
    }

    public function test_mayor_can_attest_only_after_both_reviews_are_complete(): void
    {
        $this->child();
        $planning = User::factory()->create(['role' => 'mpdc']);
        $budget = User::factory()->create(['role' => 'budget']);
        $mayor = User::factory()->create(['role' => 'mayor']);
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->decide($planning, 'complete')->assertSessionHasNoErrors();
        $this->decide($mayor, 'attest')->assertSessionHasErrors('workflow');
        $this->decide($budget, 'complete')->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $this->document()->review_status);
        $this->decide($mayor, 'attest')->assertSessionHasNoErrors();
        $this->assertSame('attested', $this->document()->review_status);
    }

    public function test_budget_cannot_review_before_mpdc_and_failed_actions_leave_no_changes(): void
    {
        $this->child();
        $budget = User::factory()->create(['role' => 'budget']);
        $planning = User::factory()->create(['role' => 'mpdc']);
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $revision = $this->document()->revision;
        $events = \App\Models\AuditEvent::count();
        foreach (['complete', 'return'] as $action) {
            $this->decide($budget, $action, ['comment' => 'Budget correction.'])
                ->assertSessionHasErrors('workflow');
            $this->assertSame($revision, $this->document()->revision);
            $this->assertSame('submitted', $this->document()->review_status);
            $this->assertSame('pending', $this->document()->budget_review);
            $this->assertSame($events, \App\Models\AuditEvent::count());
        }
        $this->decide($planning, 'complete')->assertSessionHasNoErrors();
        $this->decide($budget, 'complete')->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $this->document()->review_status);
    }

    public function test_earlier_budget_first_records_require_budget_review_after_planning(): void
    {
        $this->child();
        $planning = User::factory()->create(['role' => 'mpdc']);
        $budget = User::factory()->create(['role' => 'budget']);
        $mayor = User::factory()->create(['role' => 'mayor']);
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        // A pending record saved before review order was enforced.
        $this->document()->update(['budget_review' => 'complete']);
        $this->decide($planning, 'complete')->assertSessionHasNoErrors();
        $this->assertSame('submitted', $this->document()->review_status);
        $this->assertSame('pending', $this->document()->budget_review);
        $this->decide($mayor, 'attest')->assertSessionHasErrors('workflow');
        $this->decide($budget, 'complete')->assertSessionHasNoErrors();
        $this->decide($mayor, 'attest')->assertSessionHasNoErrors();
    }

    public function test_attestation_freezes_ppmp_costs_while_allowing_status_review(): void
    {
        $activity = $this->child();
        $item = \App\Models\CatalogueItem::create([
            'code' => 'A4', 'name' => 'Paper', 'unit' => 'ream', 'category' => 'Supplies',
            'expense_class' => 'MOOE', 'price_cents' => 20000, 'active' => true,
        ]);
        $url = '/aips/'.$activity->id.'/ppmp';
        $payload = [
            'reference' => 'PPMP-001',
            'items' => [[
                'catalogue_item_id' => $item->id, 'expected_price_cents' => 20000,
                'monthly_quantities' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ]],
        ];
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $sheet = $activity->procurementLists()->sole();
        $this->decide($this->planner, 'submit')->assertSessionHasNoErrors();
        $this->decide(User::factory()->create(['role' => 'mpdc']), 'complete')->assertSessionHasNoErrors();
        $this->decide(User::factory()->create(['role' => 'budget']), 'complete')->assertSessionHasNoErrors();
        $this->decide(User::factory()->create(['role' => 'mayor']), 'attest')->assertSessionHasNoErrors();
        $snapshot = $this->document()->submitted_snapshot;
        $changed = $payload;
        $changed['items'][0]['monthly_quantities'][0] = 5;
        foreach ([$this->planner, User::factory()->create(['role' => 'admin'])] as $user) {
            $this->actingAs($user)->get($url)->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('canEdit', false)->where('canPrepare', false)
                ->where('preparationLockedReason', 'The Mayor has attested this AIP. Its PPMP items and costs are locked.'));
            $this->post($url, [...$payload, 'reference' => 'EXTRA'])->assertSessionHasErrors('workflow');
            $this->put($url.'/'.$sheet->id, [...$changed, 'version' => 1])->assertSessionHasErrors('workflow');
            $this->put($url.'/'.$sheet->id, ['reference' => 'PPMP-001', 'version' => 1, 'items' => []])
                ->assertSessionHasErrors('workflow');
        }
        $this->assertSame(20000, $sheet->fresh()->total_cents);
        $this->assertSame(1, $sheet->fresh()->version);
        $this->assertSame(1, $activity->procurementLists()->count());
        $this->assertSame($snapshot, $this->document()->submitted_snapshot);
    }

}
