<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class ProcurementWorkflowTest extends PpaTestCase
{
    public function test_app_approval_and_numbered_pr_handoff(): void
    {
        $this->withoutVite();
        $activity = $this->child();
        $paper = CatalogueItem::create([
            'code' => 'A4', 'name' => 'Paper', 'unit' => 'ream', 'category' => 'Supplies',
            'expense_class' => 'MOOE', 'price_cents' => 25000, 'active' => true,
        ]);
        $this->post('/aips/'.$activity->id.'/ppmp', [
            'reference' => 'PPMP-TEST', 'items' => [[
                'catalogue_item_id' => $paper->id, 'expected_price_cents' => 25000,
                'monthly_quantities' => [1, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ]],
        ])->assertSessionHasNoErrors();
        $sheet = $activity->procurementLists()->sole();
        $sheet->update(['status' => 'approved']);
        $bac = User::factory()->create(['role' => 'procurement']);
        $mayor = User::factory()->create(['role' => 'mayor']);
        $treasurer = User::factory()->create(['role' => 'treasurer']);
        $gso = User::factory()->create(['role' => 'gso']);

        $build = '/procurement/app/2027/build';
        $appAction = '/procurement/app/2027/decision';
        $appUrl = '/procurement/app?year=2027';
        $requestUrl = '/procurement/requests';
        $this->actingAs($bac)->postJson($build, [])->assertUnprocessable();
        $activity->aipDocument->update(['review_status' => 'attested']);
        $this->post($build)->assertRedirect($appUrl)->assertSessionHasNoErrors();
        $this->get($appUrl)->assertInertia(fn (Assert $page) => $page->component('annual-procurement-plan')
            ->where('app.status', 'draft')->where('lines.data.0.quantity', 4)
            ->where('totalCents', 100000));
        $this->post($appAction, ['action' => 'submit', 'version' => 1])->assertSessionHasNoErrors();
        $this->actingAs($treasurer)->get('/aips')->assertRedirect('/procurement/app');
        $this->get($appUrl)->assertInertia(fn (Assert $page) => $page->where('app', null));
        $this->actingAs($this->planner)->postJson($requestUrl, [
            'year' => 2027, 'purpose' => 'Test', 'items' => [['app_source_line_id' => 1, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->actingAs($mayor)->post($appAction, ['action' => 'return', 'version' => 2, 'comment' => 'Check counts.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($bac)->post($build)->assertSessionHasNoErrors();
        $this->post($appAction, ['action' => 'submit', 'version' => 4])->assertSessionHasNoErrors();
        $this->actingAs($mayor)->post($appAction, ['action' => 'approve', 'version' => 5])->assertSessionHasNoErrors();
        $this->actingAs($treasurer)->get($appUrl)->assertInertia(fn (Assert $page) => $page->where('app.status', 'approved'));
        $line = DB::table('app_source_lines')->sole();
        $payload = ['year' => 2027, 'purpose' => 'Office paper', 'items' => [['app_source_line_id' => $line->id, 'quantity' => 3]]];
        $this->actingAs($this->planner)->post($requestUrl, $payload)->assertSessionHasNoErrors();
        $this->postJson($requestUrl, $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $record = DB::table('purchase_requests')->sole();
        $action = $requestUrl.'/'.$record->id.'/decision';
        $view = $requestUrl.'?year=2027&pr='.$record->id;
        $this->actingAs($bac)->get($view)->assertNotFound();
        $this->actingAs($gso)->postJson($action, ['action' => 'number', 'version' => 1, 'pr_number' => 'PR/2027-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->actingAs($mayor)->postJson($action, ['action' => 'approve', 'version' => 1])->assertForbidden();
        $this->actingAs($treasurer)->post($action, ['action' => 'return', 'version' => 1, 'comment' => 'Clarify purpose.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->planner)->get($view)->assertInertia(fn (Assert $page) => $page
            ->where('editing', true)->where('available.0.remaining', 4));
        $this->patch($requestUrl.'/'.$record->id, [...$payload, 'version' => 2, 'purpose' => 'Office filing'])
            ->assertSessionHasNoErrors();
        $this->actingAs($treasurer)->post($action, ['action' => 'approve', 'version' => 3])->assertSessionHasNoErrors();
        $this->actingAs($gso)->post($action, ['action' => 'number', 'version' => 4, 'pr_number' => 'pr/2027-1'])
            ->assertSessionHasNoErrors();
        $this->actingAs($bac)->get($view)->assertInertia(fn (Assert $page) => $page->component('purchase-requests')
            ->where('selected.pr_number', 'PR/2027-1')->where('selected.status', 'numbered')
            ->where('items.0.quantity', 3)->has('history', 5));

        $other = Office::create(['code' => 'OTHER', 'name' => 'Other Office']);
        $outsider = User::factory()->create(['role' => 'office', 'office_id' => $other->id]);
        $this->actingAs($outsider)->get($view)->assertNotFound();
        $this->postJson($requestUrl, $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
    }
}
