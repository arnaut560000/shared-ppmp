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

    private function ppa(AipDocument $document, array $changes = []): array
    {
        return array_replace([
            'aip_document_id' => $document->id,
            'reference' => '1000-3-1-01-003-001',
            'title' => 'Public Information and Media Services',
            'program_name' => 'Public Information Program',
            'sector' => 'Institutional Sector',
            'sub_sector' => 'Executive Services',
            'division' => 'ICT/PIO',
            'fund_source' => 'GF',
            'expected_output' => 'Regular publication of official content',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'ps' => '100.01', 'mooe' => '200.02', 'fe' => '0', 'co' => '300.03',
        ], $changes);
    }

    public function test_office_can_open_one_annual_plan_and_add_multiple_ppas(): void
    {
        $user = $this->planner();
        $this->actingAs($user)->post('/aips/documents', ['fiscal_year' => 2027])
            ->assertSessionHasNoErrors()->assertRedirect();
        $document = AipDocument::sole();
        $this->post('/aips/documents', ['fiscal_year' => 2027])->assertRedirect('/aips/documents/'.$document->id);
        $this->assertDatabaseCount('aip_documents', 1);
        $this->assertDatabaseCount('audit_events', 1);

        foreach (['PPA-001', 'PPA-002'] as $reference) {
            $this->post('/aips', $this->ppa($document, ['reference' => $reference]))
                ->assertSessionHasNoErrors()->assertRedirect('/aips/documents/'.$document->id);
        }
        $this->get('/aips?year=2027')->assertInertia(fn (Assert $page) => $page
            ->component('ppas')->has('plans.data', 2)->where('totals.total', 120012));
        $this->get('/aips/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page
            ->component('ppas')->has('plans.data', 2)->where('totals.total', 120012)
            ->where('initial.aip_document_id', (string) $document->id));
        $this->assertDatabaseHas('plans', ['program_name' => 'Public Information Program', 'division' => 'ICT/PIO']);
    }

    public function test_documents_are_isolated_by_office_and_year_including_forged_parent_ids(): void
    {
        $owner = $this->planner();
        $other = $this->planner('ENG');
        $this->actingAs($owner)->post('/aips/documents', ['office_id' => $other->office_id, 'fiscal_year' => 2027])->assertRedirect();
        $document = AipDocument::sole();
        $this->assertSame($owner->office_id, $document->office_id);
        $this->post('/aips', $this->ppa($document))->assertSessionHasNoErrors();
        $plan = Plan::sole();
        $this->post('/aips/documents', ['fiscal_year' => 2028])->assertRedirect();
        $this->get('/aips?year=2028')->assertInertia(fn (Assert $page) => $page
            ->component('ppas')->has('plans.data', 0)->where('year', 2028));
        $this->actingAs($other)->get('/aips?year=2027')->assertInertia(fn (Assert $page) => $page->has('plans.data', 0));
        $this->get('/aips/documents/'.$document->id)->assertForbidden();
        $this->get('/aips/'.$plan->id.'/view')->assertForbidden();
        $this->post('/aips', $this->ppa($document))->assertForbidden();
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_parent_scope_is_authoritative_and_readers_can_view_full_details(): void
    {
        $owner = $this->planner();
        $this->actingAs($owner)->post('/aips/documents', ['fiscal_year' => 2027]);
        $document = AipDocument::sole();
        $this->post('/aips', $this->ppa($document, ['fiscal_year' => 2028, 'office_id' => 999]))->assertSessionHasNoErrors();
        $plan = Plan::sole();
        $this->assertSame(2027, $plan->fiscal_year);
        $this->assertSame($owner->office_id, $plan->office_id);
        $reader = User::factory()->create(['role' => 'budget']);
        $this->actingAs($reader)->get('/aips/'.$plan->id.'/view')->assertInertia(fn (Assert $page) => $page
            ->component('ppas')->where('readOnly', true)->where('canWrite', false)
            ->where('initial.expected_output', 'Regular publication of official content'));
        $this->post('/aips/documents', ['office_id' => $owner->office_id, 'fiscal_year' => 2027])->assertForbidden();
        $this->post('/aips', $this->ppa($document))->assertForbidden();
    }

    public function test_admin_can_create_for_each_office_but_guests_and_unassigned_users_cannot(): void
    {
        $office = $this->planner()->office_id;
        $this->post('/aips/documents', ['office_id' => $office, 'fiscal_year' => 2027])->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'office', 'office_id' => null]))
            ->post('/aips/documents', ['office_id' => $office, 'fiscal_year' => 2027])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/aips/documents', ['office_id' => $office, 'fiscal_year' => 2027])->assertRedirect();
        $this->post('/aips/documents', ['office_id' => $office, 'fiscal_year' => 2101])->assertSessionHasErrors('fiscal_year');
        $this->post('/aips', ['aip_document_id' => 999])->assertSessionHasErrors('aip_document_id');
    }

    public function test_department_lands_directly_on_ppas_and_first_save_creates_the_container(): void
    {
        $user = $this->planner();
        $other = $this->planner('ENG');
        $this->actingAs($user)->get('/aips?year=2027')->assertInertia(fn (Assert $page) => $page
            ->component('ppas')->where('departmentUser', true)->where('document.id', null)
            ->where('initial.office_id', (string) $user->office_id)->has('plans.data', 0));
        $this->assertDatabaseCount('aip_documents', 0);
        $this->get('/aips/offices/'.$other->office_id.'?year=2027&create=1')->assertForbidden();
        $payload = $this->ppa(new AipDocument, ['aip_document_id' => '', 'office_id' => $other->office_id, 'fiscal_year' => 2027]);
        $this->post('/aips', array_replace($payload, ['title' => '']))->assertSessionHasErrors('title');
        $this->assertDatabaseCount('aip_documents', 0);
        $this->post('/aips', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($user->office_id, AipDocument::sole()->office_id);
        $this->assertSame($user->office_id, Plan::sole()->office_id);
        $this->post('/aips', array_replace($payload, ['reference' => 'SECOND']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('aip_documents', 1);
        $this->assertDatabaseCount('plans', 2);
    }

    public function test_admin_can_open_a_department_form_directly_without_creating_an_empty_plan(): void
    {
        $office = $this->planner()->office_id;
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/aips/offices/'.$office.'?year=2027&create=1')
            ->assertInertia(fn (Assert $page) => $page->component('ppas')
                ->where('startCreating', true)->where('initial.office_id', (string) $office));
        $this->assertDatabaseCount('aip_documents', 0);
        $this->actingAs(User::factory()->create(['role' => 'budget']))
            ->get('/aips/offices/'.$office.'?year=2027&create=1')->assertForbidden();
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
