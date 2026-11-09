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
                ->withCount('ppas')->withSum('ppas', 'total_cents')
                ->orderBy('office_id')->paginate(15)->withQueryString(),
            'message' => $request->session()->get('success'),
        ]);
    }

    public function update(Request $request, AipDocument $document): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office']);
        abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($data, $document, $user) {
            $document = AipDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ((int) $document->revision !== (int) $data['revision']) {
                PlanningService::fail('The AIP details changed. Reload before saving.');
            }
            $document->update([
                'title' => $data['title'], 'description' => $data['description'] ?? null,
                'revision' => $document->revision + 1,
            ]);
            PlanningService::audit($user, 'aip_document', $document->id, 'updated', $document->office_id);
        });

        return to_route('aips.documents.show', $document)->with('success', 'AIP details saved.');
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

        $details = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']]);
        $document = DB::transaction(function () use ($data, $details, $user) {
            $document = AipDocument::firstOrCreate($data, [
                'title' => $details['title'] ?? 'Annual Investment Plan '.$data['fiscal_year'],
                'description' => $details['description'] ?? null,
            ]);
            if ($document->wasRecentlyCreated) {
                PlanningService::audit($user, 'aip_document', $document->id, 'created', $document->office_id);
            }

            return $document;
        });

        return to_route('aips.documents.show', $document)
            ->with('success', $document->wasRecentlyCreated ? 'Annual plan created. Add your first program heading below.' : 'Your existing annual plan is ready.');
    }
}
