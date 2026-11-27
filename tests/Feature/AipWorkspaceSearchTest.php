<?php

namespace Tests\Feature;

use App\Models\AipDocument;
use App\Models\Office;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PpaTestCase;

class AipWorkspaceSearchTest extends PpaTestCase
{
    public function test_search_matches_a_sub_ppa_beyond_the_first_page_without_changing_aip_totals(): void
    {
        $child = $this->child(['title' => 'Field Equipment', 'reference' => 'ICT-Match-01']);
        for ($i = 1; $i <= 16; $i++) {
            $this->main->replicate()->fill(['title' => "Unrelated PPA {$i}", 'reference' => "UN-{$i}"])->save();
        }

        $this->get('/aips?year=2027')->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 17)->where('programCount', 17)
            ->where('plans.data.0.title', 'Unrelated PPA 16'));
        $this->get('/aips?year=2027&search=ict-match')->assertInertia(fn (Assert $page) => $page
            ->component('ppas')->where('search', 'ict-match')->where('plans.total', 1)->where('programCount', 17)
            ->where('plans.data.0.id', $this->main->id)->where('plans.data.0.children.0.id', $child->id));
        $this->get('/aips?year=2027&search=main+ppa')->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 1)->where('plans.data.0.id', $this->main->id));
    }

    public function test_search_treats_wildcards_as_text_and_stays_in_the_selected_aip(): void
    {
        $otherOffice = Office::create(['code' => 'ENG', 'name' => 'Engineering']);
        $otherDocument = AipDocument::create(['office_id' => $otherOffice->id, 'fiscal_year' => 2027, 'title' => 'Other AIP']);
        $this->main->replicate()->fill([
            'office_id' => $otherOffice->id, 'aip_document_id' => $otherDocument->id, 'title' => 'Private % Program',
        ])->save();

        $this->get('/aips?year=2027&search=%25')->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 0)->where('programCount', 1));
        $this->get('/aips?year=2027&search=Private')->assertInertia(fn (Assert $page) => $page->where('plans.total', 0));
        $this->main->update(['title' => 'Own % Program']);
        $this->get('/aips?year=2027&search=%25')->assertInertia(fn (Assert $page) => $page
            ->where('plans.total', 1)->where('plans.data.0.id', $this->main->id));
    }
}
