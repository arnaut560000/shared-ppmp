<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\InventoryAsset;
use App\Models\Office;
use App\Models\Plan;
use App\Models\PpmpOrderLine;
use App\Models\PpmpReceipt;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class PpmpReceivingTest extends PpaTestCase
{
    private Plan $activity;

    private Plan $sheet;

    private User $bac;

    private User $inventory;

    private string $workspace;

    private string $quarter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2027, 7, 20));
        $this->activity = $this->child();
        $this->workspace = '/aips/'.$this->activity->id.'/ppmp';
        $this->bac = User::factory()->create(['role' => 'procurement']);
        $this->inventory = User::factory()->create(['role' => 'inventory']);
        $laptop = CatalogueItem::create([
            'code' => 'LAP', 'name' => 'Laptop', 'unit' => 'unit', 'category' => 'Equipment',
            'expense_class' => 'CO', 'price_cents' => 25000, 'active' => true,
        ]);
        $this->post($this->workspace, [
            'reference' => 'ICT-PPMP',
            'items' => [[
                'catalogue_item_id' => $laptop->id,
                'expected_price_cents' => 25000,
                'monthly_quantities' => [4, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ]],
        ])->assertSessionHasNoErrors();
        $this->sheet = $this->activity->procurementLists()->sole();
        $this->quarter = '/inventory/ppmp-items/'.$this->sheet->items()->sole()->id.'/quarters/1';
    }

    public function test_late_partial_delivery_updates_the_original_planned_quarter_and_registers_only_accepted_assets(): void
    {
        $this->actingAs($this->bac)->postJson($this->quarter.'/orders', $this->order())->assertNotFound();
        $this->sheet->update(['status' => 'approved']);
        $this->actingAs($this->inventory)->postJson($this->quarter.'/orders', $this->order())->assertForbidden();
        $this->actingAs($this->bac)->post($this->quarter.'/orders', $this->order())
            ->assertRedirect($this->quarter)->assertSessionHasNoErrors();
        $order = PpmpOrderLine::sole();
        $this->postJson($this->quarter.'/orders', $this->order(['reference' => 'OTHER-PO', 'quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $receipts = $this->quarter.'/orders/'.$order->id.'/receipts';
        $this->postJson($receipts, [])->assertForbidden();

        $units = array_map(fn ($number) => ['property_tag' => 'LAP-'.$number, 'serial_number' => 'S-'.$number], range(1, 6));
        $receipt = ['reference' => 'DR-1', 'received_on' => '2027-04-10', 'quantity' => 6, 'units' => $units];
        $this->actingAs($this->inventory)->postJson($receipts, [...$receipt, 'units' => array_slice($units, 0, 5)])
            ->assertUnprocessable()->assertJsonValidationErrors('units');
        $this->assertDatabaseCount('inventory_assets', 0);
        $this->post($receipts, $receipt)->assertRedirect($this->quarter)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_assets', 6);
        $this->assertSame(6, PpmpReceipt::sole()->assets()->count());
        $this->assertDatabaseHas('inventory_assets', [
            'property_tag' => 'LAP-1', 'office_id' => $this->planner->office_id, 'receipt_reference' => 'PO-2027',
        ]);
        $this->assertSame('2027-04-10', InventoryAsset::where('property_tag', 'LAP-1')->sole()->received_on->toDateString());
        $this->postJson($receipts, [...$receipt, 'reference' => 'DR-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson($receipts, [...$receipt, 'reference' => 'DR-3', 'quantity' => 1, 'units' => [$units[0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units');
        $this->assertDatabaseCount('ppmp_receipts', 1);

        $this->get($this->quarter)->assertInertia(fn (Assert $page) => $page
            ->component('inventory/ppmp-receiving')->where('planned', 10)->where('quarter', 1)
            ->where('orders.0.received', 6)->where('orders.0.receipts.0.received_on', '2027-04-10'));
        $catalogueId = $this->sheet->items()->sole()->catalogue_item_id;
        $this->actingAs($this->planner)->get($this->workspace)->assertInertia(fn (Assert $page) => $page
            ->where("receiving.{$catalogueId}.0.ordered", 10)->where("receiving.{$catalogueId}.0.received", 6)
            ->where("receiving.{$catalogueId}.1", null));
        $this->get('/inventory/purchase-orders?search=PO-2027')->assertInertia(fn (Assert $page) => $page
            ->where('orders.total', 1)->where('orders.data.0.received', 6));
    }

    public function test_quantity_only_receipts_and_office_isolation(): void
    {
        $this->sheet->update(['status' => 'approved']);
        $this->actingAs($this->bac)->post($this->quarter.'/orders', $this->order(['register_equipment' => false]))
            ->assertSessionHasNoErrors();
        $order = PpmpOrderLine::sole();
        $this->actingAs($this->inventory)->post($this->quarter.'/orders/'.$order->id.'/receipts', [
            'reference' => 'DR-1', 'received_on' => '2027-05-10', 'quantity' => 3,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, PpmpReceipt::sole()->quantity);
        $this->assertSame(0, InventoryAsset::count());

        $other = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $outsider = User::factory()->create(['role' => 'office', 'office_id' => $other->id]);
        $this->actingAs($outsider)->get($this->quarter)->assertForbidden();
        $this->get('/inventory/purchase-orders')->assertInertia(fn (Assert $page) => $page->where('orders.total', 0));
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'reference' => ' po-2027 ', 'ordered_on' => '2027-02-20', 'quantity' => 10, 'register_equipment' => true,
        ];
    }
}
