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
}
