<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use App\Services\OfficeBudgetService;
use Tests\PpaTestCase;

class PlanningDeletionTest extends PpaTestCase
{
    private function procurement(Plan $ppa): Plan
    {
        $item = CatalogueItem::create(['code' => 'PEN', 'name' => 'Pen', 'unit' => 'pc', 'category' => 'Supplies',
            'expense_class' => 'MOOE', 'price_cents' => 500]);
        $this->post(route('ppmp.store', $ppa), ['reference' => 'SHEET', 'items' => [[
            'catalogue_item_id' => $item->id, 'expected_price_cents' => 500, 'monthly_quantities' => array_fill(0, 12, 1),
        ]]])->assertSessionHasNoErrors();

        return $ppa->procurementLists()->sole();
    }

    public function test_document_review_and_confirmation_remove_only_its_tree_and_release_spending(): void
    {
        $ppa = $this->child(['ps' => '100']);
        $sheet = $this->procurement($ppa);
        $other = $this->main->aipDocument->replicate();
        $other->fiscal_year = 2028;
        $other->save();
        $url = '/aips/deletion/aip/'.$ppa->aip_document_id;
        $preview = $this->getJson($url)->assertOk()->assertJsonPath('counts.ppas', 1)->assertJsonPath('counts.sub_ppas', 1)
            ->assertJsonPath('counts.ppmps', 1)->assertJsonPath('counts.items', 1)->json();
        $this->deleteJson($url, ['token' => $preview['token']])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->deleteJson($url, ['confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertModelExists($sheet);
        $this->delete($url, ['confirmed' => true, 'token' => $preview['token']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertModelMissing($sheet);
        $this->assertModelMissing($ppa);
        $this->assertModelMissing($this->main);
        $this->assertDatabaseMissing('aip_documents', ['id' => $ppa->aip_document_id]);
        $this->assertDatabaseCount('plan_items', 0);
        $this->assertDatabaseCount('catalogue_items', 1);
        $this->assertModelExists($other);
        $this->assertSame(1000000, Budget::sole()->ceiling_cents);
        $this->assertSame(1000000, OfficeBudgetService::summary($ppa->office_id, 2027)['remaining_cents']);
        $this->assertDatabaseHas('audit_events', ['subject_type' => 'aip_document', 'subject_id' => $ppa->aip_document_id, 'action' => 'deleted']);
    }

    public function test_deleting_a_sub_ppa_keeps_its_sibling_and_parent_and_deleting_the_parent_keeps_the_document(): void
    {
        $first = $this->child();
        $this->procurement($first);
        $second = $this->child(['reference' => 'KEEP']);
        $doc = $this->main->aipDocument;
        $url = '/aips/deletion/sub-ppa/'.$first->id;
        $token = $this->getJson($url)->assertJsonPath('counts.ppas', 0)->assertJsonPath('counts.sub_ppas', 1)->json('token');
        $this->delete($url, ['confirmed' => true, 'token' => $token])->assertSessionHasNoErrors();
        $this->assertModelMissing($first);
        $this->assertModelExists($second);
        $this->assertModelExists($this->main);
        $url = '/aips/deletion/ppa/'.$this->main->id;
        $token = $this->getJson($url)->assertOk()->json('token');
        $this->delete($url, ['confirmed' => true, 'token' => $token])->assertSessionHasNoErrors();
        $this->assertModelMissing($second);
        $this->assertModelExists($doc);
    }

    public function test_new_children_and_locked_descendants_prevent_a_stale_cascade(): void
    {
        $url = '/aips/deletion/ppa/'.$this->main->id;
        $token = $this->getJson($url)->assertOk()->json('token');
        $ppa = $this->child();
        $this->deleteJson($url, ['confirmed' => true, 'token' => $token])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $sheet = $this->procurement($ppa);
        $sheet->update(['status' => 'approved']);
        $preview = $this->getJson($url)->assertOk()->json();
        $this->assertNotNull($preview['blocked']);
        $this->deleteJson($url, ['confirmed' => true, 'token' => $preview['token']])->assertUnprocessable();
        $this->assertModelExists($sheet);
        $this->assertModelExists($ppa);
        $this->assertModelExists($this->main);
    }

    public function test_other_offices_and_reviewers_cannot_preview_or_confirm(): void
    {
        $url = '/aips/deletion/aip/'.$this->main->aip_document_id;
        $token = $this->getJson($url)->assertOk()->json('token');
        $other = Office::create(['name' => 'Engineering', 'code' => 'ENG']);
        foreach (['office', 'budget'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'office_id' => $other->id]));
            $this->getJson($url)->assertForbidden();
            $this->deleteJson($url, ['confirmed' => true, 'token' => $token])->assertForbidden();
        }
        $this->assertModelExists($this->main);
    }
}
