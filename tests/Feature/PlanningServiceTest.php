<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use App\Services\ConsolidatedPpmpReport;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    private Office $office;

    private User $planner;

    private User $budgetOfficer;

    private User $mayor;

    private PlanningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $this->planner = User::factory()->create(['role' => 'office', 'office_id' => $this->office->id]);
        $this->budgetOfficer = User::factory()->create(['role' => 'budget']);
        $this->mayor = User::factory()->create(['role' => 'mayor']);
        Budget::create(['office_id' => $this->office->id, 'fiscal_year' => 2027, 'ceiling_cents' => 100000]);
        $this->service = app(PlanningService::class);
    }

    private function aip(array $overrides = []): Plan
    {
        return $this->service->save($this->planner, array_replace([
            'type' => 'aip', 'office_id' => $this->office->id, 'fiscal_year' => 2027,
            'reference' => 'AIP-001', 'title' => 'Office supplies', 'fund_source' => 'General Fund',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
            'ps' => '0', 'mooe' => '100', 'fe' => '0', 'co' => '200',
        ], $overrides));
    }

    private function approvedAip(): Plan
    {
        $aip = $this->aip();
        $this->service->transition($this->planner, $aip, 'submit', 1, null);
        $this->service->transition($this->budgetOfficer, $aip->fresh(), 'approve', 2, null);

        return $aip->fresh();
    }

    public function test_quarterly_approval_uses_catalogue_prices_and_preserves_report_snapshots(): void
    {
        $aip = $this->approvedAip();
        $item = CatalogueItem::create([
            'code' => 'PAPER', 'name' => 'Paper', 'specification' => 'A4', 'unit' => 'ream',
            'category' => 'Office supplies', 'expense_class' => 'MOOE', 'price_cents' => 2500,
        ]);
        $ppmp = $this->service->save($this->planner, [
            'type' => 'ppmp', 'office_id' => $this->office->id, 'fiscal_year' => 2027,
            'reference' => 'PPMP-001', 'title' => 'Quarterly supplies', 'fund_source' => 'General Fund',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'parent_id' => $aip->id,
            'total_cents' => 1,
            'items' => [['catalogue_item_id' => $item->id, 'quarterly_quantities' => [1, 2, 0, 1], 'price_cents' => 1]],
        ]);
        $this->assertSame(10000, $ppmp->total_cents);
        $this->assertSame([1, 2, 0, 1], $ppmp->items()->first()->quarterly_quantities);
        $this->assertNull($ppmp->items()->first()->monthly_quantities);
        $this->assertSame(0, app(ConsolidatedPpmpReport::class)->build($this->planner, 2027)['plan_count']);
        $this->service->transition($this->planner, $ppmp, 'submit', 1, null);
        $this->service->transition($this->mayor, $ppmp->fresh(), 'approve', 2, null);
        $item->update(['price_cents' => 9000, 'category' => 'New category']);
        $report = app(ConsolidatedPpmpReport::class)->build($this->planner, 2027);
        $this->assertSame(1, $report['plan_count']);
        $this->assertSame(10000, $report['total_cents']);
        $this->assertSame(2500, $report['rows'][0]['price_cents']);
        $this->assertSame('Office supplies', $report['rows'][0]['category']);
        $this->assertSame([2500, 5000, 0, 2500], $report['rows'][0]['offices'][$this->office->id]['amounts']);
        $this->assertSame(6, AuditEvent::count());
        $this->assertSame(0, app(ConsolidatedPpmpReport::class)->build($this->planner, 2026)['plan_count']);
        $this->assertSame(0, app(ConsolidatedPpmpReport::class)->build($this->planner, 2027, null, 'Special Fund')['plan_count']);
    }

    public function test_aip_submission_cannot_overreserve_an_office_ceiling(): void
    {
        $first = $this->aip();
        $this->service->transition($this->planner, $first, 'submit', 1, null);
        $second = $this->aip(['reference' => 'AIP-002', 'mooe' => '800', 'co' => '0']);
        try {
            $this->service->transition($this->planner, $second, 'submit', 1, null);
            $this->fail('An oversubscribed AIP was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('budget ceiling', $exception->errors()['workflow'][0]);
        }
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->version);
    }

    public function test_another_office_cannot_edit_a_plan(): void
    {
        $otherOffice = Office::create(['code' => 'OTHER', 'name' => 'Other office']);
        $otherPlanner = User::factory()->create(['role' => 'office', 'office_id' => $otherOffice->id]);
        $this->expectException(HttpException::class);
        PlanningAccess::edit($otherPlanner, $this->aip());
    }

    public function test_mayor_cannot_approve_an_aip_reserved_for_budget_review(): void
    {
        $aip = $this->aip();
        $this->service->transition($this->planner, $aip, 'submit', 1, null);
        $this->expectException(HttpException::class);
        $this->service->transition($this->mayor, $aip->fresh(), 'approve', 2, null);
    }

    public function test_stale_versions_cannot_submit_a_document(): void
    {
        $aip = $this->aip();
        $this->expectException(ValidationException::class);
        $this->service->transition($this->planner, $aip, 'submit', 0, null);
    }

    public function test_approved_documents_cannot_be_edited(): void
    {
        $aip = $this->approvedAip();
        $this->expectException(HttpException::class);
        PlanningAccess::edit($this->planner, $aip);
    }

    public function test_return_requires_a_reason_and_releases_the_reservation(): void
    {
        $aip = $this->aip();
        $this->service->transition($this->planner, $aip, 'submit', 1, null);
        try {
            $this->service->transition($this->budgetOfficer, $aip->fresh(), 'return', 2, ' ');
            $this->fail('A return without a reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('revised', $exception->errors()['workflow'][0]);
        }
        $this->assertSame('submitted', $aip->fresh()->status);
        $this->service->transition($this->budgetOfficer, $aip->fresh(), 'return', 2, 'Revise the estimate.');
        $replacement = $this->aip(['reference' => 'AIP-002', 'mooe' => '1000', 'co' => '0']);
        $this->service->transition($this->planner, $replacement, 'submit', 1, null);
        $this->assertSame('submitted', $replacement->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $aip->id, 'action' => 'return', 'comment' => 'Revise the estimate.']);
    }

    public function test_office_report_cannot_request_another_office(): void
    {
        $otherOffice = Office::create(['code' => 'OTHER', 'name' => 'Other office']);
        $this->expectException(HttpException::class);
        app(ConsolidatedPpmpReport::class)->build($this->planner, 2027, $otherOffice->id);
    }
}
