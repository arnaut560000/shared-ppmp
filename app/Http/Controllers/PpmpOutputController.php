<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanningAccess;
use App\Services\PpmpWorkbook;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class PpmpOutputController extends Controller
{
    public function __invoke(Request $request, Plan $ppa, int $sheet, string $format, PpmpWorkbook $workbooks): Response
    {
        PlanningAccess::viewPpmpWorkspace($request->user(), $ppa);

        $ppmp = $ppa->procurementLists()
            ->where('office_id', $ppa->office_id)
            ->where('fiscal_year', $ppa->fiscal_year)
            ->with(['items' => fn ($query) => $query->orderBy('id')])
            ->findOrFail($sheet);

        $book = $workbooks->build($ppa, $ppmp);
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
