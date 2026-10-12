<?php

namespace App\Http\Controllers;

use App\Models\PrintBatch;
use App\Models\ProductionItem;
use App\Models\ProductionSync;
use App\Services\BacklogSyncService;
use App\Services\ProductionService;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(
        private ProductionService $svc,
        private BacklogSyncService $backlogSync
    ) {}

    public function dashboard()
    {
        $items = ProductionItem::with(['decision', 'batchItems.batch', 'printHistories'])->get();
        $ready = $this->svc->queue();

        return view('dashboard.index', [
            'total' => $items->count(),
            'ready' => $ready->count(),
            'review' => $items->where('match_status', 'REVIEW')->count(),
            'custom' => $items->where('is_custom', true)->count(),
            'summary' => $this->svc->summary($ready),
            'batches' => PrintBatch::latest()->limit(5)->get(),
            'lastSync' => ProductionSync::latest()->first(),
        ]);
    }

    public function syncBacklog(Request $request)
    {
        $mode = $request->validate([
            'mode' => 'nullable|in:unfulfilled,unfulfilled_partial',
        ])['mode'] ?? 'unfulfilled';

        try {
            $result = $this->backlogSync->sync($mode, $request->user()->id);

            return redirect()->route('dashboard')->with('ok', sprintf(
                'Shopify backlog refreshed: %d current, %d orders examined, %d added, %d updated, %d unchanged, %d matched, %d missing, %d quantity deltas.',
                $result['shopify_items'],
                $result['orders_examined'],
                $result['added'],
                $result['updated'],
                $result['unchanged'],
                $result['matched'],
                $result['missing'],
                $result['quantity_deltas']
            ));
        } catch (\Throwable $e) {
            report($e);
            $this->backlogSync->recordFailure($mode, $e->getMessage(), $request->user()->id);

            return redirect()->route('dashboard')
                ->with('error', 'Shopify backlog refresh failed. Check details below.')
                ->with('error_details', $e->getMessage());
        }
    }

    public function verification()
    {
        $items = ProductionItem::with('decision')
            ->orderByRaw("
                CASE match_status
                    WHEN 'REVIEW' THEN 0
                    WHEN 'MISSING' THEN 1
                    WHEN 'CUSTOM' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('order_number')
            ->get();

        foreach ($items as $item) {
            $item->ready_for_print = $this->svc->ready($item);
            $item->approval_current = $this->svc->current($item);
        }

        return view('production.verification', compact('items'));
    }

    public function verify(Request $request, ProductionItem $item)
    {
        $decision = $request->validate([
            'decision' => 'required|in:CORRECT,WRONG',
        ])['decision'];

        $this->svc->verify($item, $decision, $request->user()->id);

        return back()->with(
            'ok',
            $decision === 'CORRECT' ? 'Artwork approved.' : 'Artwork marked wrong.'
        );
    }

    public function queue()
    {
        $items = $this->svc->queue();

        return view('production.queue', [
            'items' => $items,
            'summary' => $this->svc->summary($items),
            'materials' => ProductionService::MATERIALS,
        ]);
    }

    public function previewBatch(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*' => 'nullable|integer|min:0',
        ]);

        return view('batches.preview', $this->svc->preview($data['items']));
    }

    public function batch(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*' => 'nullable|integer|min:0',
        ]);

        $batch = $this->svc->batch($request->user()->id, $data['items']);

        return redirect('/batches/'.$batch->id)
            ->with('ok', 'Production batch created.');
    }

    public function batches()
    {
        return view('batches.index', [
            'batches' => PrintBatch::withCount('items')->latest()->get(),
        ]);
    }

    public function showBatch(PrintBatch $batch)
    {
        $batch->load('items.productionItem', 'histories');

        return view('batches.show', compact('batch'));
    }

    public function requestEdgePrint(Request $request, PrintBatch $batch)
    {
        $this->svc->transition($batch, 'HANDOFF_REQUESTED');

        return redirect('/batches/'.$batch->id)
            ->with('ok', 'Edge Print handoff requested. Waiting for Factory PC. Auto Print remains OFF.');
    }

    public function markPrinted(Request $request, PrintBatch $batch)
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.completed_quantity' => 'required|integer|min:0',
            'items.*.failed_quantity' => 'required|integer|min:0',
            'items.*.reason' => 'nullable|string|max:500',
        ]);

        $this->svc->confirmPrinted($batch, $data['items'], $request->user()->id);

        return redirect('/batches/'.$batch->id)
            ->with('ok', 'Physical print results recorded.');
    }

    public function history(Request $request)
    {
        $q = trim((string) $request->query('q'));

        $items = ProductionItem::with('printHistories.batch')
            ->whereHas('printHistories', function ($query) {
                $query->where('result', 'COMPLETED')
                    ->where('batch_type', 'normal');
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('order_number', 'like', "%{$q}%")
                        ->orWhere('product', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('line_item_id', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('production.history', [
            'items' => $items,
            'q' => $q,
            'svc' => $this->svc,
        ]);
    }

    public function reprint(Request $request, ProductionItem $item)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
            'confirm' => 'accepted',
        ]);

        $batch = $this->svc->createReprintBatch(
            $item,
            (int) $data['quantity'],
            $data['reason'],
            $request->user()->id
        );

        return redirect('/batches/'.$batch->id)
            ->with('ok', 'Reprint batch created without changing Shopify fulfillment or original print history.');
    }
}
