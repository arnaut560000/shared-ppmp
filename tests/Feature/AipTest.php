<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AipTest extends TestCase
{
    use RefreshDatabase;

    private Office $office;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $this->planner = User::factory()->create(['role' => 'office', 'office_id' => $this->office->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'office_id' => $this->office->id,
            'fiscal_year' => 2027,
            'reference' => 'AIP-ICT-2027-001',
            'title' => 'Office equipment',
            'description' => 'Equipment for office operations',
            'fund_source' => 'General Fund',
            'expected_output' => 'Improved office capacity',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'ps' => '0',
            'mooe' => '100000.25',
            'fe' => '0',
            'co' => '50000',
        ], $overrides);
    }

    private function createDraft(): Plan
    {
        $this->actingAs($this->planner)->post('/aips', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('aips.index', ['year' => 2027]));

        return Plan::sole();
    }

    public function test_guest_is_redirected_and_unassigned_office_gets_no_data(): void
    {
        $this->get('/aips')->assertRedirect('/login');
        $this->createDraft();
        $unassigned = User::factory()->create(['role' => 'office']);

        $this->actingAs($unassigned)->get('/aips?year=2027')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('aips')
                ->has('offices', 0)->has('plans.data', 0));
        $this->post('/aips', $this->payload())->assertForbidden();
    }
}
