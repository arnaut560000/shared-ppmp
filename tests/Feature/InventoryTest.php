<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Office $ict;

    private Office $engineering;

    private User $custodian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 7, 10));
        $this->ict = Office::create(['code' => 'ICT', 'name' => 'ICT Office']);
        $this->engineering = Office::create(['code' => 'ENG', 'name' => 'Engineering Office']);
        $this->custodian = User::factory()->create(['role' => 'inventory']);
    }

    public function test_property_custodian_can_register_assign_transfer_and_return_equipment(): void
    {
        $maria = $this->employee($this->ict);
        $jose = $this->employee($this->engineering);
        $asset = $this->register();
        $details = '/inventory/'.$asset->id;
        $url = $details.'/movements';

        $this->postJson($url, ['action' => 'assign', 'version' => 1, 'holder_id' => $jose->id])
            ->assertUnprocessable()->assertJsonValidationErrors('holder_id');

        $this->post($url, ['action' => 'assign', 'version' => 1, 'holder_id' => $maria->id])
            ->assertRedirect($details)->assertSessionHasNoErrors();
        $this->assertSame($maria->name, $asset->fresh()->holder_name);
        $this->postJson($url, ['action' => 'return', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('movement');

        $this->post($url, [
            'action' => 'transfer', 'version' => 2, 'destination_office_id' => $this->engineering->id,
            'holder_id' => $jose->id, 'remarks' => 'Reassigned to Engineering.',
        ])->assertRedirect($details)->assertSessionHasNoErrors();
        $asset->refresh();
        $this->assertEquals($this->engineering->id, $asset->office_id);
        $this->assertEquals($this->ict->id, $asset->received_for_office_id);
        $this->assertSame($jose->name, $asset->holder_name);
        $this->actingAs($maria)->get($details)->assertForbidden();
        $this->actingAs($jose)->get($details)->assertInertia(fn (Assert $page) => $page
            ->where('asset.holder_name', $jose->name)->where('history', null)->where('canManage', false));

        $this->actingAs($this->custodian)->post($url, ['action' => 'return', 'version' => 3])
            ->assertRedirect($details)->assertSessionHasNoErrors();
        $this->get($details)->assertInertia(fn (Assert $page) => $page
            ->component('inventory/show')->where('asset.holder_name', null)->where('asset.version', 4)
            ->where('history.total', 4)->where('history.data.0.action', 'return')
            ->where('history.data.0.actor_name', $this->custodian->name)
            ->where('history.data.1.from_holder_name', $maria->name)
            ->where('history.data.1.to_holder_name', $jose->name)
            ->where('history.data.1.remarks', 'Reassigned to Engineering.'));
    }

    public function test_inventory_scope_and_receiving_quarters_use_the_correct_office(): void
    {
        $asset = $this->register();
        $holder = $this->employee($this->engineering);
        $this->post('/inventory/'.$asset->id.'/movements', [
            'action' => 'transfer', 'version' => 1, 'destination_office_id' => $this->engineering->id,
            'holder_id' => $holder->id,
        ])->assertSessionHasNoErrors();
        $this->register(['property_tag' => 'ICT-002', 'received_on' => '2026-07-01']);

        $department = $this->employee($this->ict);
        $this->actingAs($department)->get('/inventory')->assertInertia(fn (Assert $page) => $page
            ->component('inventory/index')->where('assets.total', 1)->where('assets.data.0.property_tag', 'ICT-002'));
        $this->get('/inventory?office_id='.$this->engineering->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'office']))->get('/inventory')
            ->assertInertia(fn (Assert $page) => $page->where('assets.total', 0)->has('offices', 0));

        $bac = User::factory()->create(['role' => 'procurement']);
        $this->actingAs($bac)->get('/inventory?year=2026&quarter=2&office_id='.$this->ict->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('receivingView', true)->where('canManage', false)->where('assets.total', 1)
                ->where('assets.data.0.id', $asset->id)->where('assets.data.0.office', $this->ict->name)
                ->missing('assets.data.0.holder_name')->missing('assets.data.0.serial_number'));
        $this->get('/inventory?year=2026&quarter=3')->assertInertia(fn (Assert $page) => $page
            ->where('assets.total', 1)->where('assets.data.0.property_tag', 'ICT-002'));
        $this->get('/inventory/'.$asset->id)->assertForbidden();
        $this->postJson('/inventory/'.$asset->id.'/movements', [])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'gso']))->get('/inventory?office_id='.$this->engineering->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('assets.total', 1)->where('assets.data.0.holder_name', $holder->name)->where('canManage', false));
        $this->postJson('/inventory', [])->assertForbidden();
        $this->postJson('/inventory/'.$asset->id.'/movements', [])->assertForbidden();
    }

    public function test_registration_rejects_duplicate_property_tags(): void
    {
        $this->register(['property_tag' => ' ict-001 ']);

        $this->postJson('/inventory', $this->receipt(['property_tag' => 'ICT-001']))
            ->assertUnprocessable()->assertJsonValidationErrors('property_tag');
        $this->assertDatabaseCount('inventory_assets', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    private function employee(Office $office): User
    {
        return User::factory()->create(['role' => 'office', 'office_id' => $office->id]);
    }

    private function register(array $overrides = []): InventoryAsset
    {
        $data = $this->receipt($overrides);
        $this->actingAs($this->custodian)->post('/inventory', $data)->assertRedirect()->assertSessionHasNoErrors();

        return InventoryAsset::where('property_tag', strtoupper(trim($data['property_tag'])))->sole();
    }

    private function receipt(array $overrides): array
    {
        return $overrides + [
            'property_tag' => 'ICT-001', 'name' => 'Laptop', 'serial_number' => 'SN0001',
            'received_on' => '2026-06-30', 'receipt_reference' => 'PO-001', 'office_id' => $this->ict->id,
        ];
    }
}
