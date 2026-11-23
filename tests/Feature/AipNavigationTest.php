<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AipNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_visiting_guest_pages_are_sent_to_aips(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/login')->assertRedirect('/aips');
        $this->get('/register')->assertRedirect('/aips');
    }

    public function test_dashboard_remains_removed_and_budget_allocation_is_available_to_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get('/dashboard')->assertNotFound();
        $this->get('/budgets')->assertOk();
        $this->post('/budgets', [])->assertSessionHasErrors(['office_id', 'fiscal_year', 'amount']);
    }

    public function test_administration_still_allows_admin_to_create_an_office(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get('/administration')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('administration'));
        $this->post('/administration/offices', ['code' => 'ICT', 'name' => 'ICT Office'])
            ->assertSessionHasNoErrors()->assertRedirect(route('administration.index'));
        $this->assertDatabaseHas('offices', ['code' => 'ICT', 'name' => 'ICT Office']);
    }

    public function test_admin_can_edit_an_office_without_breaking_user_assignments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $employee = User::factory()->create(['role' => 'office', 'office_id' => $office->id]);

        $this->actingAs($admin)->patch('/administration/offices/'.$office->id, [
            'code' => ' ict ', 'name' => 'Information and Communications Technology',
        ])->assertSessionHasNoErrors()->assertRedirect(route('administration.index'));

        $this->assertDatabaseHas('offices', [
            'id' => $office->id, 'code' => 'ICT', 'name' => 'Information and Communications Technology',
        ]);
        $this->assertEquals($office->id, $employee->fresh()->office_id);

        $event = AuditEvent::where('subject_type', 'office')->where('subject_id', $office->id)->sole();
        $this->assertSame('updated', $event->action);
        $this->assertSame('ICT Office', $event->metadata['before']['name']);
        $this->assertSame('Information and Communications Technology', $event->metadata['after']['name']);
    }

    public function test_office_edit_rejects_duplicate_codes_and_non_admin_users(): void
    {
        $office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        Office::create(['code' => 'GSO', 'name' => 'General Services Office']);
        $url = '/administration/offices/'.$office->id;
        $changes = ['code' => 'gso', 'name' => 'Other office'];

        $this->actingAs(User::factory()->create(['role' => 'office', 'office_id' => $office->id]))
            ->patchJson($url, $changes)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson($url, $changes)->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('offices', ['id' => $office->id, 'code' => 'ICT', 'name' => 'ICT Office']);
    }

    public function test_office_users_still_cannot_access_administration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'office']));

        $this->get('/administration')->assertForbidden();
        $this->post('/administration/offices', ['code' => 'ICT', 'name' => 'ICT Office'])->assertForbidden();
        $this->assertDatabaseCount('offices', 0);
    }
}
