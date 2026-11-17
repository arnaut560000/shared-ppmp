<?php

namespace App\Services;

use App\Models\Plan;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PpmpWorkbook
{
    public function build(Plan $activity, Plan $sheet): Spreadsheet
    {
        $book = IOFactory::load(resource_path('templates/ppmp-form-a.xlsx'));
        $page = $book->getActiveSheet();
        $status = match ($sheet->status) {
            'pending_gso' => 'Awaiting GSO review',
            'submitted' => 'Awaiting approval',
            default => ucfirst($sheet->status),
        };

        $book->getProperties()->setTitle('Monthly PPMP – '.$sheet->reference);
        foreach ([
            'A2' => 'PROCUREMENT LIST · '.$sheet->reference.' · '.$status,
            'A3' => 'CY '.$sheet->fiscal_year,
            'C4' => $activity->office->name,
            'C6' => $activity->reference,
            'C7' => $activity->title,
        ] as $cell => $value) {
            $page->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }

        // Retain the form's styles for the class heading, item and grand-total rows.
        $styles = [];
        foreach ([12, 13, 14] as $sample) {
            foreach (range('A', 'Z') as $column) {
                $styles[$sample][$column] = $page->getCell($column.$sample)->getXfIndex();
            }
        }
        $groups = $sheet->items->groupBy('expense_class');
        abort_if($groups->keys()->diff(['MOOE', 'CO'])->isNotEmpty(), 409, 'An item has an unknown expense class.');
        $count = $sheet->items->count();
        abort_if($count === 0, 409, 'Save at least one item before exporting this sheet.');
        $page->insertNewRowBefore(14, $count + 2); // Two headings and two subtotals, less two template rows.

        $row = 12;
        $subtotals = [];
        foreach (['MOOE' => 'Maintenance and Other Operating Expenses (MOOE)', 'CO' => 'Capital Outlay (CO)'] as $class => $label) {
            $this->styleRow($page, $row, $styles[12]);
            $page->mergeCells("A{$row}:Z{$row}")->setCellValue("A{$row}", $label);
            $page->getStyle("A{$row}:Z{$row}")->getFont()->setBold(true)->getColor()->setRGB('000000');
            $page->getRowDimension($row)->setRowHeight(20);
            $row++;
            $start = $row;
            foreach ($groups->get($class, collect()) as $item) {
                $this->styleRow($page, $row, $styles[13]);
                $monthly = count($item->monthly_quantities ?? []) === 12;
                abort_unless($monthly || count($item->quarterly_quantities ?? []) === 4, 409, 'Missing schedule for '.$item->code.'.');
                $description = implode("\n", array_filter([
                    $item->name, $item->specification, $monthly ? null : '(Monthly breakdown unavailable)',
                ], fn ($value) => $value !== null && $value !== ''));
                foreach (['A' => $item->code, 'B' => $description, 'C' => $item->unit] as $column => $value) {
                    $page->setCellValueExplicit($column.$row, $value, DataType::TYPE_STRING);
                }
                $page->getStyle("B{$row}")->getAlignment()->setWrapText(true);
                $page->getRowDimension($row)->setRowHeight(-1);
                $page->setCellValue("D{$row}", $item->price_cents / 100);
                foreach ([['E','F','G','H','V'], ['I','J','K','L','W'], ['M','N','O','P','X'], ['Q','R','S','T','Y']] as $q => [$first,$middle,$last,$qty,$cost]) {
                    if ($monthly) {
                        foreach ([$first, $middle, $last] as $offset => $column) {
                            $page->setCellValue($column.$row, $item->monthly_quantities[$q * 3 + $offset]);
                        }
                        $page->setCellValue($qty.$row, "=SUM({$first}{$row}:{$last}{$row})");
                    } else {
                        $page->setCellValue($qty.$row, $item->quarterly_quantities[$q]);
                    }
                    $page->setCellValue($cost.$row, "=ROUND(D{$row}*{$qty}{$row},2)");
                }
                $page->setCellValue("U{$row}", "=H{$row}+L{$row}+P{$row}+T{$row}");
                $page->setCellValue("Z{$row}", "=SUM(V{$row}:Y{$row})");
                $row++;
            }
            $this->styleRow($page, $row, $styles[14]);
            $page->mergeCells("A{$row}:T{$row}")->setCellValue("A{$row}", $class.' subtotal');
            $end = $row - 1;
            foreach (range('V', 'Z') as $column) {
                $page->setCellValue($column.$row, $end < $start ? 0 : "=SUM({$column}{$start}:{$column}{$end})");
            }
            $subtotals[] = $row++;
        }

        [$mooe, $co] = $subtotals;
        $page->mergeCells("A{$row}:T{$row}")->setCellValue("A{$row}", 'TOTAL PPMP');
        foreach (range('V', 'Z') as $column) {
            $page->setCellValue($column.$row, "={$column}{$mooe}+{$column}{$co}");
        }
        $page->setCellValue('C8', "=Z{$row}");
        $page->getStyle('C8')->getNumberFormat()->setFormatCode('"₱"#,##0.00');
        $page->getStyle("D12:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $page->getStyle("V12:Z{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $page->getPageSetup()->setPrintArea('A1:Z'.($row + 5));
        $page->setSelectedCell('A1');

        return $book;
    }

    private function styleRow(Worksheet $page, int $row, array $styles): void
    {
        foreach ($styles as $column => $style) {
            $page->getCell($column.$row)->setXfIndex($style);
        }
    }
}
