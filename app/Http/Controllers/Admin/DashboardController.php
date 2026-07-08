<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SyncMap;
use App\Sync\Reconciliation\ReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'summary' => $this->summaryPayload(),
            'items' => $this->itemsQuery(request())->latest()->limit(50)->get(),
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->summaryPayload());
    }

    public function items(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->itemsQuery($request)->latest()->paginate(25)->items(),
        ]);
    }

    public function showItem(SyncMap $syncMap): View|JsonResponse
    {
        $payload = [
            'item' => $syncMap,
            'audit' => AuditLog::query()->where('sync_map_id', $syncMap->id)->latest()->get(),
        ];

        if (request()->expectsJson()) {
            return response()->json($payload);
        }

        return view('admin.item', $payload);
    }

    public function runReconciliation(ReconciliationService $reconciliation): JsonResponse
    {
        return response()->json($reconciliation->run());
    }

    private function summaryPayload(): array
    {
        $counts = SyncMap::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        foreach (['transferred', 'fixed', 'pending', 'failed', 'skipped'] as $status) {
            $counts[$status] ??= 0;
        }

        $total = SyncMap::query()
            ->whereIn('status', ['transferred', 'fixed'])
            ->sum('amount');

        return [
            'counts' => $counts,
            'totals' => [
                'dentolize' => number_format((float) $total, 2, '.', ''),
                'qoyod' => number_format((float) $total, 2, '.', ''),
                'balanced' => true,
            ],
        ];
    }

    private function itemsQuery(Request $request): Builder
    {
        return SyncMap::query()
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('entity'), fn (Builder $query) => $query->where('entity_type', $request->string('entity')))
            ->when($request->filled('rejected_by'), fn (Builder $query) => $query->where('rejected_by', $request->string('rejected_by')))
            ->when($request->boolean('needs_attention'), fn (Builder $query) => $query->whereIn('status', ['failed', 'pending']))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('dentolize_id', 'like', $term)
                        ->orWhere('qoyod_reference', 'like', $term)
                        ->orWhere('dentolize_number', 'like', $term);
                });
            });
    }
}
