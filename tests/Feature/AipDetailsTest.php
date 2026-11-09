<?php

namespace Tests\Feature;

use App\Models\AipDocument;
use App\Models\Office;
use App\Models\User;
use Tests\PpaTestCase;

class AipDetailsTest extends PpaTestCase
{
    public function test_department_can_create_and_edit_details_without_overwriting_a_newer_revision(): void
    {
        $data = ['fiscal_year' => 2028, 'title' => 'ICT work plan', 'description' => 'Annual activities'];
        $this->post('/aips/documents', $data)->assertSessionHasNoErrors();
        $doc = AipDocument::where('fiscal_year', 2028)->sole();
        $this->assertSame($this->planner->office_id, $doc->office_id);
        $this->assertSame('ICT work plan', $doc->title);
        $this->post('/aips/documents', [...$data, 'title' => 'Duplicate'])->assertRedirect();
        $this->assertSame('ICT work plan', $doc->fresh()->title);
        $url = '/aips/documents/'.$doc->id;
        $this->put($url, [...$data, 'title' => 'Revised work plan', 'revision' => 1])->assertSessionHasNoErrors();
        $this->put($url, [...$data, 'revision' => 1])->assertSessionHasErrors('workflow');
        $this->assertSame('Revised work plan', $doc->fresh()->title);
        $this->assertSame(2, (int) $doc->fresh()->revision);
    }

    public function test_budget_reviewers_and_other_departments_cannot_edit_aip_details(): void
    {
        $doc = $this->main->aipDocument;
        $other = Office::create(['code' => 'OTHER', 'name' => 'Other office']);
        foreach ([User::factory()->create(['role' => 'office', 'office_id' => $other->id]), User::factory()->create(['role' => 'budget'])] as $user) {
            $this->actingAs($user)->put('/aips/documents/'.$doc->id, ['title' => 'Cross-role change', 'revision' => 1])->assertForbidden();
        }
        $this->assertSame(1, (int) $doc->fresh()->revision);
    }
}
