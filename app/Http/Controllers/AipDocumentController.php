<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\Office;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AipDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office', 'budget', 'mayor', 'procurement', 'gso', 'inventory']);
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = (int) ($data['year'] ?? now()->year);

        if ($user->role === 'office' && $user->office_id) {
            return app(AipController::class)->workspace($request, $user->office);
        }

        return Inertia::render('aips', [
            'year' => $year,
            'canWrite' => in_array($user->role, ['admin', 'office'], true),
            'offices' => Office::query()
                ->when($user->role === 'office', fn ($q) => $q->whereKey($user->office_id ?? 0))
                ->orderBy('name')->get(['id', 'code', 'name']),
            'documents' => AipDocument::query()->visibleTo($user)
                ->where('fiscal_year', $year)->with('office:id,code,name')
                ->withCount('ppas')->withSum('mainPpas as ppas_sum_total_cents', 'total_cents')
                ->orderBy('office_id')->paginate(15)->withQueryString(),
            'message' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office']);
        if ($user->role === 'office') {
            abort_unless($user->office_id, 403, 'Your account needs an assigned office.');
            $request->merge(['office_id' => $user->office_id]);
        }
        $data = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'fiscal_year' => ['required', 'integer', 'between:2020,2100'],
        ]);

        $document = DB::transaction(function () use ($data, $user) {
            $document = AipDocument::firstOrCreate($data, [
                'title' => 'Annual Investment Plan '.$data['fiscal_year'],
            ]);
            if ($document->wasRecentlyCreated) {
                PlanningService::audit($user, 'aip_document', $document->id, 'created', $document->office_id);
            }

            return $document;
        });

        return to_route('aips.documents.show', $document)
            ->with('success', $document->wasRecentlyCreated ? 'Annual plan created. Add your first PPA below.' : 'Your existing annual plan is ready.');
    }
}
