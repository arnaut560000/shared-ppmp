<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CatalogueItem;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class PpmpApprovalTest extends PpaTestCase
{
    private Plan $activity;

    private User $gso;

    private User $mayor;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activity = $this->child();
        $this->workspace = '/aips/'.$this->activity->id.'/ppmp';
        $this->gso = User::factory()->create(['role' => 'gso']);
        $this->mayor = User::factory()->create(['role' => 'mayor']);
        $paper = CatalogueItem::create([
            'code' => 'A4', 'name' => 'Paper', 'unit' => 'ream', 'category' => 'Supplies',
            'expense_class' => 'MOOE', 'price_cents' => 25000, 'active' => true,
        ]);
        $this->post($this->workspace, [
            'reference' => 'PPMP-TEST',
            'items' => [[
                'catalogue_item_id' => $paper->id,
                'expected_price_cents' => 25000,
                'monthly_quantities' => [2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ]],
        ])->assertSessionHasNoErrors();
    }

    public function test_mayor_can_only_approve_the_version_reviewed_by_gso(): void
    {
        $sheet = $this->activity->procurementLists()->sole();
        $queue = '/ppmp-approvals?year=2027';
        $decision = '/ppmp-approvals/'.$sheet->id;
        $this->get($queue)->assertForbidden();
        $this->postJson($decision, [])->assertForbidden();

        $sheet->update(['status' => 'submitted']);
        $this->actingAs($this->mayor)->get($queue)->assertInertia(fn (Assert $page) => $page
            ->component('ppmp-approval')->where('sheets.total', 0));
        $this->postJson($decision, $this->decisionPayload($sheet))->assertUnprocessable()
            ->assertJsonValidationErrors('workflow');

        $sheet->update(['status' => 'draft']);
        $sheet = $this->completeGsoReview($sheet);
        $this->get('/consolidated-ppmp?year=2027')->assertInertia(fn (Assert $page) => $page->where('planCount', 0));
        $this->postJson($decision, $this->decisionPayload($sheet))->assertForbidden();

        $this->actingAs($this->mayor)->get($queue)->assertInertia(fn (Assert $page) => $page
            ->where('sheets.total', 1)->where('sheets.data.0.id', $sheet->id));
        $this->postJson($decision, [...$this->decisionPayload($sheet), 'version' => $sheet->version - 1])
            ->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->post($decision, $this->decisionPayload($sheet))->assertRedirect($queue)->assertSessionHasNoErrors();
        $this->assertSame('approved', $sheet->fresh()->status);
        $this->get($queue)->assertInertia(fn (Assert $page) => $page->where('sheets.total', 0));
        $this->get($this->workspace.'?sheet='.$sheet->id)->assertInertia(fn (Assert $page) => $page
            ->where('reviewHistory.0.action', 'approve')->where('reviewHistory.0.actor', $this->mayor->name)
            ->has('reviewHistory.0.created_at'));
        $this->actingAs($this->gso)->get('/consolidated-ppmp?year=2027')->assertInertia(fn (Assert $page) => $page
            ->where('planCount', 1)->where('totalCents', 50000));
    }

    public function test_return_requires_explicit_authorization_to_reopen_attested_aip(): void
    {
        $sheet = $this->completeGsoReview($this->activity->procurementLists()->sole());
        $annual = $this->activity->aipDocument;
        $snapshot = ['approved_total_cents' => 50000];
        $annual->update([
            'review_status' => 'attested', 'planning_review' => 'complete', 'budget_review' => 'complete',
            'submitted_snapshot' => $snapshot,
        ]);
        $decision = '/ppmp-approvals/'.$sheet->id;
        $payload = [...$this->decisionPayload($sheet), 'action' => 'return'];
        $this->actingAs($this->mayor)->postJson($decision, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $payload['comment'] = 'Update the planned quantity.';
        $this->postJson($decision, $payload)->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->assertSame('attested', $annual->fresh()->review_status);
        $this->assertSame('submitted', $sheet->fresh()->status);

        $this->post($decision, [...$payload, 'authorize_revision' => true])
            ->assertRedirect('/ppmp-approvals?year=2027')->assertSessionHasNoErrors();
        $this->assertSame('returned', $sheet->fresh()->status);
        $this->assertDatabaseHas('aip_documents', [
            'id' => $annual->id, 'review_status' => 'returned', 'planning_review' => 'pending',
            'budget_review' => 'pending', 'revision' => $annual->revision + 1,
        ]);
        $event = AuditEvent::where('subject_type', 'aip_review')->where('subject_id', $annual->id)->sole();
        $this->assertSame($snapshot, $event->metadata['previous_snapshot']);
        $this->actingAs($this->planner)->get($this->workspace.'?sheet='.$sheet->id)
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', true)
                ->where('reviewHistory.0.action', 'return')->where('reviewHistory.0.comment', $payload['comment']));
    }

    private function completeGsoReview(Plan $sheet): Plan
    {
        $review = $this->workspace.'/'.$sheet->id.'/review';
        $this->actingAs($this->planner)->post($review, ['action' => 'submit_to_gso', 'version' => $sheet->version])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->gso)->post($review, [
            'action' => 'complete_gso_review', 'version' => $sheet->fresh()->version,
        ])->assertSessionHasNoErrors();

        return $sheet->fresh();
    }

    private function decisionPayload(Plan $sheet): array
    {
        return [
            'action' => 'approve', 'version' => $sheet->version,
            'annual_revision' => $this->activity->aipDocument()->firstOrFail()->revision,
            'authorize_revision' => false,
        ];
    }
}
