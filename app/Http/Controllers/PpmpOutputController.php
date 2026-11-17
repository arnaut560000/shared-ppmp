<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanningAccess;
use App\Services\PpmpWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Writer\Html;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class PpmpOutputController extends Controller
{
    public function __invoke(Request $request, Plan $ppa, int $sheet, string $format, PpmpWorkbook $workbooks): Response
    {
        PlanningAccess::viewPpmpWorkspace($request->user(), $ppa);

        $ppmp = DB::transaction(function () use ($ppa, $sheet) {
            return $ppa->procurementLists()
                ->where('office_id', $ppa->office_id)
                ->where('fiscal_year', $ppa->fiscal_year)
                ->with(['items' => fn ($query) => $query->orderBy('id')])
                ->findOrFail($sheet);
        });
        if ($request->has('version') && $request->integer('version') !== $ppmp->version) {
            abort(409, 'This PPMP has changed. Reload the sheet before opening the output.');
        }

        $book = $workbooks->build($ppa, $ppmp);
        if ($format === 'preview') {
            try {
                $writer = new Html($book);
                $writer->setGenerateSheetNavigationBlock(false);
                $nonce = bin2hex(random_bytes(16));

                return response()->view('ppmp-preview', [
                    'nonce' => $nonce,
                    'reference' => $ppmp->reference,
                    'styles' => $writer->generateStyles(false),
                    'table' => $writer->generateSheetData(),
                    'download' => route('ppmp.output', [
                        'ppa' => $ppa, 'sheet' => $sheet, 'format' => 'xlsx', 'version' => $ppmp->version,
                    ]),
                ], 200, [
                    'Cache-Control' => 'private, no-store',
                    'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'self'",
                ]);
            } finally {
                $book->disconnectWorksheets();
            }
        }

        return response()->streamDownload(function () use ($book): void {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, "PPMP-{$ppmp->fiscal_year}-{$ppmp->id}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
