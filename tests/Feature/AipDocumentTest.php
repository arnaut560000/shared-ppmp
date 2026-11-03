<?php

namespace Tests\Feature;

use App\Models\AipDocument;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AipDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function planner(string $code = 'ICT'): User
    {
        $office = Office::create(['code' => $code, 'name' => $code.' Office']);

        return User::factory()->create(['role' => 'office', 'office_id' => $office->id]);
    }

    public function test_department_opens_its_own_workspace_without_creating_records(): void
    {
        $owner = $this->planner();
        $other = $this->planner('ENG');
        $this->actingAs($owner)->get('/aips?year=2027')->assertInertia(fn (Assert $p) => $p
            ->component('ppas')->where('document.id', null)->where('initial.office_id', (string) $owner->office_id)
            ->where('budget.ceiling_cents', null)->has('plans.data', 0));
        $this->assertDatabaseCount('aip_documents', 0);
        $this->get('/aips/offices/'.$other->office_id.'?year=2027')->assertForbidden();

        $data = ['office_id' => $other->office_id, 'fiscal_year' => 2027, 'reference' => 'PROGRAM', 'title' => 'Information'];
        $this->post('/aips', [...$data, 'title' => ''])->assertSessionHasErrors('title');
        $this->assertDatabaseCount('aip_documents', 0);
        $this->post('/aips', $data)->assertSessionHasNoErrors();
        $this->assertSame($owner->office_id, AipDocument::sole()->office_id);
        $this->assertSame('program', Plan::sole()->type);
        $this->assertSame(0, Plan::sole()->total_cents);
        $this->post('/aips', [...$data, 'reference' => 'SECOND'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('aip_documents', 1);
        $this->get('/aips?year=2028')->assertInertia(fn (Assert $p) => $p->has('plans.data', 0));
    }

    public function test_annual_document_scope_and_office_permissions_are_enforced(): void
    {
        $owner = $this->planner();
        $other = $this->planner('ENG');
        $this->actingAs($owner)->post('/aips/documents', ['fiscal_year' => 2027])->assertSessionHasNoErrors();
        $document = AipDocument::sole();
        $this->post('/aips/documents', ['fiscal_year' => 2027])->assertRedirect('/aips/documents/'.$document->id);
        $this->assertDatabaseCount('aip_documents', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $data = ['aip_document_id' => $document->id, 'office_id' => 999, 'fiscal_year' => 2028,
            'reference' => 'PROGRAM', 'title' => 'Information'];
        $this->post('/aips', $data)->assertSessionHasNoErrors();
        $plan = Plan::sole();
        $this->assertSame(2027, $plan->fiscal_year);
        $this->assertSame($owner->office_id, $plan->office_id);
        $this->actingAs($other)->get('/aips/documents/'.$document->id)->assertForbidden();
        $this->get('/aips/'.$plan->id.'/view')->assertForbidden();
        $this->post('/aips', $data)->assertForbidden();
        $this->get('/aips?year=2027')->assertInertia(fn (Assert $p) => $p->has('plans.data', 0));
    }

    public function test_guests_unassigned_users_and_reviewers_cannot_create_headings(): void
    {
        $office = $this->planner()->office_id;
        $data = ['office_id' => $office, 'fiscal_year' => 2027, 'reference' => 'PROGRAM', 'title' => 'Information'];
        $this->post('/aips', $data)->assertRedirect('/login');
        foreach ([
            User::factory()->create(['role' => 'office', 'office_id' => null]),
            User::factory()->create(['role' => 'budget']),
        ] as $user) {
            $this->actingAs($user)->post('/aips', $data)->assertForbidden();
            $this->post('/aips/documents', $data)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/aips', $data)->assertSessionHasNoErrors();
        $this->post('/aips', [...$data, 'aip_document_id' => 999])->assertSessionHasErrors('aip_document_id');
        $this->post('/aips/documents', [...$data, 'fiscal_year' => 2101])->assertSessionHasErrors('fiscal_year');
    }

    public function test_admin_can_open_new_department_and_reviewers_are_read_only(): void
    {
        $office = $this->planner()->office_id;
        $url = '/aips/offices/'.$office.'?year=2027&create=1';
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url)
            ->assertInertia(fn (Assert $p) => $p->component('ppas')->where('editorVisible', true)
                ->where('isProgram', true)->where('initial.office_id', (string) $office));
        $this->assertDatabaseCount('aip_documents', 0);
        $this->actingAs(User::factory()->create(['role' => 'budget']))->get($url)
            ->assertInertia(fn (Assert $p) => $p->where('canWrite', false)->where('editorVisible', false));
    }

    public function test_migration_groups_existing_rows_without_changing_amounts_statuses_or_links(): void
    {
        $migration = require database_path('migrations/2026_10_30_000001_add_aip_documents.php');
        $migration->down();
        $owner = $this->planner();
        $fields = [
            'office_id' => $owner->office_id, 'fiscal_year' => 2027,
            'type' => 'aip', 'reference' => 'LEGACY-001', 'title' => 'Existing activity',
            'fund_source' => 'GF', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
            'created_by' => $owner->id, 'status' => 'approved', 'version' => 4,
            'mooe_cents' => 20000, 'total_cents' => 20000,
        ];
        $first = Plan::create($fields);
        $second = Plan::create(array_replace($fields, ['reference' => 'LEGACY-002', 'status' => 'draft']));
        $child = Plan::create(array_replace($fields, ['type' => 'ppmp', 'reference' => 'PPMP-001', 'parent_id' => $first->id]));
        $migration->up();
        $document = AipDocument::sole();
        $this->assertSame($document->id, $first->fresh()->aip_document_id);
        $this->assertSame($document->id, $second->fresh()->aip_document_id);
        $this->assertSame(40000, (int) $document->ppas()->sum('total_cents'));
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame(4, $first->fresh()->version);
        $this->assertSame($first->id, $child->fresh()->parent_id);
        $this->assertNull($child->fresh()->aip_document_id);
    }
}
