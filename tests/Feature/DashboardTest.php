<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertOk();
    }

    public function test_office_overview_filters_by_office_and_year_and_reserves_only_active_aips(): void
    {
        $office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $other = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $user = User::factory()->create(['office_id' => $office->id]);

        Budget::create(['office_id' => $office->id, 'fiscal_year' => 2027, 'ceiling_cents' => 100000]);
        Budget::create(['office_id' => $other->id, 'fiscal_year' => 2027, 'ceiling_cents' => 900000]);
        Budget::create(['office_id' => $office->id, 'fiscal_year' => 2028, 'ceiling_cents' => 800000]);

        $this->plan($user, $office, 'submitted', 10000);
        $this->plan($user, $office, 'approved', 20000);
        $this->plan($user, $office, 'draft', 30000);
        $this->plan($user, $office, 'returned', 40000);
        $this->plan($user, $office, 'approved', 5000, ['type' => 'ppmp']);
        $this->plan($user, $other, 'approved', 70000);
        $this->plan($user, $office, 'approved', 60000, ['fiscal_year' => 2028]);

        $this->actingAs($user)->get('/dashboard?year=2027')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('year', 2027)
                ->where('account.office', 'ICT Office')
                ->where('account.needsOffice', false)
                ->where('stats.ceiling', 100000)
                ->where('stats.reserved', 30000)
                ->where('stats.available', 70000)
                ->where('stats.counts', ['draft' => 1, 'submitted' => 1, 'approved' => 2, 'returned' => 1])
                ->has('recent', 5)
                ->where('recent', fn ($plans) => $plans->every(fn ($plan) => $plan['office'] === 'ICT'))
            );
    }

    public function test_unassigned_office_user_cannot_see_existing_office_data(): void
    {
        $office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $user = User::factory()->create();
        Budget::create(['office_id' => $office->id, 'fiscal_year' => 2027, 'ceiling_cents' => 100000]);
        $this->plan($user, $office, 'approved', 20000);

        $this->actingAs($user)->get('/dashboard?year=2027')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('account.needsOffice', true)
                ->where('stats.ceiling', 0)
                ->where('stats.reserved', 0)
                ->where('stats.available', 0)
                ->where('stats.counts.approved', 0)
                ->has('recent', 0)
            );
    }

    public function test_admin_overview_includes_both_offices(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        foreach (['ICT', 'ENG'] as $code) {
            $office = Office::create(['code' => $code, 'name' => $code.' Office']);
            Budget::create(['office_id' => $office->id, 'fiscal_year' => 2027, 'ceiling_cents' => 100000]);
            $this->plan($user, $office, 'approved', 20000);
        }

        $this->actingAs($user)->get('/dashboard?year=2027')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('stats.ceiling', 200000)
                ->where('stats.reserved', 40000)
                ->where('stats.available', 160000)
                ->where('stats.counts.approved', 2)
                ->has('recent', 2)
            );
    }

    private function plan(User $user, Office $office, string $status, int $total, array $overrides = []): Plan
    {
        return Plan::create(array_replace([
            'office_id' => $office->id,
            'created_by' => $user->id,
            'fiscal_year' => 2027,
            'type' => 'aip',
            'reference' => 'PLAN-'.(Plan::count() + 1),
            'title' => 'Office requirements',
            'fund_source' => 'General Fund',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => $status,
            'total_cents' => $total,
        ], $overrides));
    }
}
