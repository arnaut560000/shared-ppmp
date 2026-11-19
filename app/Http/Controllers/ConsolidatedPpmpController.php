<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\ConsolidatedPpmpReport;
use App\Services\PlanningAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ConsolidatedPpmpController extends Controller
{
    public function __invoke(Request $request, ConsolidatedPpmpReport $reports)
    {
        PlanningAccess::roles($request->user(), ['gso', 'admin']);

        $filters = $request->validate([
            'year' => ['sometimes', 'required', 'integer', 'between:2020,2100'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
        ]);

        $year = (int) ($filters['year'] ?? now()->year);
        $officeId = isset($filters['office_id']) ? (int) $filters['office_id'] : null;

        $report = DB::transaction(fn () => $reports->build(
            $request->user(),
            $year,
            $officeId,
            consolidatePrices: true,
        ));

        $classes = [
            'MOOE' => [0, 0, 0, 0],
            'CO' => [0, 0, 0, 0],
        ];

        foreach ($report['rows'] as $row) {
            foreach ($row['amounts'] as $quarter => $amount) {
                $classes[$row['expense_class']][$quarter] += $amount;
            }
        }

        $offices = Office::orderBy('name')->get(['id', 'name']);

        if ($request->routeIs('ppmp.consolidated.xlsx')) {
            $officeNames = $offices->pluck('name', 'id')->all();
            $scope = $officeId === null ? 'All departments' : $officeNames[$officeId];
            $filename = "Consolidated-PPMP-{$year}-".($officeId ?? 'all').'.xlsx';

            return response()->streamDownload(
                function () use ($year, $scope, $report, $classes, $officeNames) {
                    $book = new Spreadsheet();

                    try {
                        $summary = $book->getActiveSheet();
                        $summary->setTitle('Consolidated PPMP');

                        $this->writeRow($summary, 1, ["Consolidated PPMP · FY {$year}", $scope]);
                        $this->writeRow($summary, 2, [
                            'Class', 'Code', 'Item', 'Specification', 'Unit',
                            'Q1 qty', 'Q1 PHP', 'Q2 qty', 'Q2 PHP',
                            'Q3 qty', 'Q3 PHP', 'Q4 qty', 'Q4 PHP',
                            'Annual qty', 'Annual PHP',
                        ]);

                        $line = 3;

                        foreach ($report['rows'] as $item) {
                            $values = [
                                $item['expense_class'], $item['code'], $item['name'],
                                $item['specification'], $item['unit'],
                            ];

                            foreach ($item['quantities'] as $q => $qty) {
                                $values[] = $qty;
                                $values[] = $item['amounts'][$q] / 100;
                            }

                            $values[] = array_sum($item['quantities']);
                            $values[] = array_sum($item['amounts']) / 100;
                            $this->writeRow($summary, $line++, $values);
                        }

                        $grand = [0, 0, 0, 0];
                        foreach ($classes as $class => $amounts) {
                            foreach ($amounts as $q => $amount) {
                                $grand[$q] += $amount;
                            }
                        }

                        foreach ([...$classes, 'Grand total' => $grand] as $label => $amounts) {
                            $values = [$label, null, null, null, null];
                            foreach ($amounts as $amount) {
                                $values[] = null;
                                $values[] = $amount / 100;
                            }
                            $values[] = null;
                            $values[] = array_sum($amounts) / 100;
                            $this->writeRow($summary, $line++, $values);
                        }

                        $sources = $book->createSheet();
                        $sources->setTitle('Source sheets');
                        $this->writeRow($sources, 1, ["Source sheets · FY {$year}", $scope]);
                        $this->writeRow($sources, 2, [
                            'Office', 'Parent PPA', 'Sub PPA', 'PPMP reference',
                            'Class', 'Item code', 'Item', 'Unit', 'Unit price PHP',
                            'Q1 qty', 'Q1 PHP', 'Q2 qty', 'Q2 PHP',
                            'Q3 qty', 'Q3 PHP', 'Q4 qty', 'Q4 PHP', 'Annual PHP',
                        ]);

                        $sourceLine = 3;
                        foreach ($report['rows'] as $item) {
                            foreach ($item['sources'] as $source) {
                                $values = [
                                    $officeNames[$source['office_id']],
                                    $source['ppa'], $source['activity'], $source['reference'],
                                    $item['expense_class'], $item['code'], $item['name'],
                                    $item['unit'], $source['price_cents'] / 100,
                                ];
                                foreach ($source['quantities'] as $q => $qty) {
                                    $values[] = $qty;
                                    $values[] = $source['amounts'][$q] / 100;
                                }
                                $values[] = array_sum($source['amounts']) / 100;
                                $this->writeRow($sources, $sourceLine++, $values);
                            }
                        }

                        $this->formatSheet($summary, 'O', ['G', 'I', 'K', 'M', 'O']);
                        $this->formatSheet($sources, 'R', ['I', 'K', 'M', 'O', 'Q', 'R']);
                        $book->setActiveSheetIndex(0);
                        (new Xlsx($book))->save('php://output');
                    } finally {
                        $book->disconnectWorksheets();
                    }
                },
                $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'],
            );
        }

        return Inertia::render('consolidated-ppmp', [
            'year' => $year,
            'officeId' => $officeId,
            'offices' => $offices,
            'items' => $report['rows'],
            'classes' => $classes,
            'totalCents' => $report['total_cents'],
            'planCount' => $report['plan_count'],
        ]);
    }

    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }

            $cell = [$index + 1, $row];

            if (is_string($value)) {
                // Preserve leading zeroes and keep user-entered text literal in Excel.
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($cell, $value);
            }
        }
    }

    private function formatSheet(Worksheet $sheet, string $lastColumn, array $moneyColumns): void
    {
        $sheet->freezePane('F3');
        $sheet->getStyle("A1:{$lastColumn}2")->getFont()->setBold(true);

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $lastRow = $sheet->getHighestDataRow();
        if ($lastRow >= 3) {
            foreach ($moneyColumns as $column) {
                $sheet->getStyle("{$column}3:{$column}{$lastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }
    }
}