<?php

namespace Tests\Feature;

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

    public function test_dashboard_and_budget_endpoints_are_removed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get('/dashboard')->assertNotFound();
        $this->get('/budgets')->assertNotFound();
        $this->post('/budgets', [])->assertNotFound();
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

    public function test_office_users_still_cannot_access_administration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'office']));

        $this->get('/administration')->assertForbidden();
        $this->post('/administration/offices', ['code' => 'ICT', 'name' => 'ICT Office'])->assertForbidden();
        $this->assertDatabaseCount('offices', 0);
    }
}
