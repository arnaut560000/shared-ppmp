<?php

namespace Tests;

use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class PpaTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $planner;

    protected Plan $main;

    protected function setUp(): void
    {
        parent::setUp();
        $office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $this->planner = User::factory()->create(['role' => 'office', 'office_id' => $office->id]);
        $this->main = app(PlanningService::class)->save($this->planner, $this->data());
        $this->actingAs($this->planner);
    }

    protected function data(array $changes = []): array
    {
        return array_replace([
            'type' => 'aip', 'office_id' => $this->planner->office_id, 'fiscal_year' => 2027,
            'reference' => '2027-0-1', 'title' => 'Main PPA', 'fund_source' => 'GF',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
            'ps' => '1000', 'mooe' => '10000', 'fe' => '100', 'co' => '2000',
        ], $changes);
    }

    protected function childData(array $changes = []): array
    {
        return $this->data(array_replace([
            'aip_document_id' => $this->main->aip_document_id,
            'parent_ppa_id' => $this->main->id,
            'reference' => '2027-0-1-001', 'title' => 'Sub-PPA',
            'ps' => '0', 'mooe' => '6000', 'fe' => '0', 'co' => '0',
        ], $changes));
    }

    protected function child(array $changes = []): Plan
    {
        $data = $this->childData($changes);
        $this->post('/aips', $data)->assertSessionHasNoErrors()->assertRedirect();

        return Plan::where('reference', $data['reference'])->sole();
    }
}
