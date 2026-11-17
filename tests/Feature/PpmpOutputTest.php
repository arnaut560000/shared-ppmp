<?php

namespace Tests\Feature;

use App\Models\CatalogueItem;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\PpaTestCase;

class PpmpOutputTest extends PpaTestCase
{
    private Plan $activity;
    private Plan $sheet;
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activity = $this->child();
        $items = [];
        foreach ([
            ['A-1', 'MOOE', 4300, [2,0,0,3,0,0,0,0,0,0,0,0]],
            ['CO-1', 'CO', 15000, [0,0,0,0,0,0,0,0,0,0,0,1]],
        ] as [$code, $expense, $price, $months]) {
            $item = CatalogueItem::create([
                'code' => $code, 'name' => '=Example <item>', 'unit' => 'piece',
                'category' => 'Test', 'expense_class' => $expense, 'price_cents' => $price, 'active' => true,
            ]);
            $items[] = ['catalogue_item_id' => $item->id, 'expected_price_cents' => $price, 'monthly_quantities' => $months];
        }
        $this->post('/aips/'.$this->activity->id.'/ppmp', ['reference' => 'PPMP-TEST', 'items' => $items])->assertSessionHasNoErrors();
        $this->sheet = $this->activity->procurementLists()->sole();
        $this->url = "/aips/{$this->activity->id}/ppmp/{$this->sheet->id}/output/";
    }

    private function download()
    {
        $response = $this->get($this->url.'xlsx')->assertOk();
        $response->assertDownload("PPMP-2027-{$this->sheet->id}.xlsx");
        $path = tempnam(sys_get_temp_dir(), 'ppmp');
        try {
            file_put_contents($path, $response->streamedContent());
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            unlink($path);
        }
    }

    public function test_download_uses_saved_prices_and_reconciles_each_quarter(): void
    {
        CatalogueItem::where('code', 'A-1')->update(['price_cents' => 50000, 'active' => false]);
        $page = $this->download();
        $this->assertSame('=Example <item>', $page->getCell('B13')->getValue());
        $this->assertSame('s', $page->getCell('B13')->getDataType());
        $this->assertEquals(43, $page->getCell('D13')->getValue());
        foreach (['V18' => 86, 'W18' => 129, 'X18' => 0, 'Y18' => 150, 'C8' => 365] as $cell => $value) {
            $this->assertEquals($value, $page->getCell($cell)->getCalculatedValue(), $cell);
        }
        $this->assertEquals($this->sheet->total_cents / 100, $page->getCell('Z18')->getCalculatedValue());
        $this->assertSame('landscape', $page->getPageSetup()->getOrientation());
    }

    public function test_quarterly_only_rows_keep_months_blank(): void
    {
        $this->sheet->items()->where('code', 'A-1')->update(['monthly_quantities' => null]);
        $page = $this->download();
        $this->assertNull($page->getCell('E13')->getValue());
        $this->assertEquals(2, $page->getCell('H13')->getValue());
        $this->assertEquals(3, $page->getCell('L13')->getValue());
        $this->assertStringContainsString('Monthly breakdown unavailable', $page->getCell('B13')->getValue());
        $this->assertEquals(365, $page->getCell('C8')->getCalculatedValue());
    }
}
