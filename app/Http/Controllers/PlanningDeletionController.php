<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\Office;
use App\Models\Plan;
use App\Models\PlanItem;
use App\Models\User;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanningDeletionController extends Controller
{
    public function preview(Request $request, string $kind, int $id): JsonResponse
    {
        $target = $this->target($request->user(), $kind, $id);
        $scope = DB::transaction(fn () => $this->scope($request->user(), $kind, $target));

        return response()->json($scope['preview'])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $kind, int $id): RedirectResponse
    {
        $user = $request->user();
        $target = $this->target($user, $kind, $id);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'token' => ['required', 'string', 'size:64']]);

        DB::transaction(function () use ($kind, $id, $user, $target, $data) {
            // The office lock is also acquired by writers of plans, procurement sheets and budgets.
            Office::whereKey($target->office_id)->lockForUpdate()->firstOrFail();
            $fresh = $target->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            $scope = $this->scope($user, $kind, $fresh);
            $preview = $scope['preview'];
            if ($preview['blocked']) {
                PlanningService::fail($preview['blocked']);
            }
            if (! hash_equals($preview['token'], $data['token'])) {
                PlanningService::fail('These records changed. Close this dialog and review the deletion again.');
            }

            foreach (['sheets', 'activities', 'programs'] as $level) {
                Plan::whereIntegerInRaw('id', $scope[$level])->delete();
            }
            if ($kind === 'aip') {
                $fresh->delete();
            }
            PlanningService::audit($user, $kind === 'aip' ? 'aip_document' : 'plan', $id, 'deleted', $fresh->office_id, null, [
                'kind' => $kind, 'title' => $fresh->title, 'fiscal_year' => $fresh->fiscal_year,
                'counts' => $preview['counts'], 'plan_ids' => array_merge($scope['programs'], $scope['activities'], $scope['sheets']),
            ]);
        }, 3);

        return to_route('aips.offices.workspace', ['office' => $target->office_id, 'year' => $target->fiscal_year])
            ->with('success', 'Deleted “'.$target->title.'”.');
    }

    private function target(User $user, string $kind, int $id): AipDocument|Plan
    {
        PlanningAccess::roles($user, ['admin', 'office']);
        $target = $kind === 'aip' ? AipDocument::findOrFail($id)
            : Plan::where('type', $kind === 'ppa' ? 'program' : 'aip')->findOrFail($id);
        abort_if($user->role === 'office' && $user->office_id !== $target->office_id, 403);

        return $target;
    }

    private function scope(User $user, string $kind, AipDocument|Plan $target): array
    {
        $documentId = $kind === 'aip' ? $target->id : $target->aip_document_id;
        abort_unless($documentId, 409, 'This record has no annual plan.');
        $plans = Plan::where('office_id', $target->office_id)->where('fiscal_year', $target->fiscal_year);
        $members = (clone $plans)->where('aip_document_id', $documentId);
        $programs = (clone $members)->where('type', 'program')
            ->when($kind !== 'aip', fn ($q) => $q->whereKey($kind === 'ppa' ? $target->id : 0))
            ->orderBy('id')->get(['id', 'version', 'status']);
        $activities = (clone $members)->where('type', 'aip')
            ->when($kind === 'ppa', fn ($q) => $q->where('parent_ppa_id', $target->id))
            ->when($kind === 'sub-ppa', fn ($q) => $q->whereKey($target->id))
            ->orderBy('id')->get(['id', 'version', 'status']);
        $sheets = (clone $plans)->where('type', 'ppmp')->whereIntegerInRaw('parent_id', $activities->modelKeys())
            ->orderBy('id')->get(['id', 'version', 'status']);
        $counts = [
            'ppas' => $programs->count(), 'sub_ppas' => $activities->count(), 'ppmps' => $sheets->count(),
            'items' => PlanItem::whereIntegerInRaw('plan_id', $sheets->modelKeys())->count(),
        ];
        $annual = $kind === 'aip' ? $target : AipDocument::findOrFail($documentId);
        $locked = $annual->isReviewLocked() || $activities->concat($sheets)->contains(fn (Plan $p) => ! in_array($p->status, ['draft', 'returned'], true));
        $signature = [$user->id, $kind, $target->id, $target->office_id, $target->fiscal_year, $target->revision ?? $target->version,
            $programs->toArray(), $activities->toArray(), $sheets->toArray(), $counts];

        return [
            'programs' => $programs->modelKeys(), 'activities' => $activities->modelKeys(), 'sheets' => $sheets->modelKeys(),
            'preview' => [
                'title' => $target->title, 'year' => $target->fiscal_year, 'counts' => $counts,
                'blocked' => $locked ? 'The annual AIP or a linked record is locked. This selection cannot be deleted.' : null,
                'token' => hash_hmac('sha256', json_encode($signature, JSON_THROW_ON_ERROR), config('app.key')),
            ],
        ];
    }
}
