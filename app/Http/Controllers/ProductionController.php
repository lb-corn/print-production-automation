<?php

namespace App\Http\Controllers;

use App\Models\ProductionItem;
use App\Models\PrintBatch;
use App\Services\ProductionService;
use App\Services\BacklogSyncService;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(
        private ProductionService $svc
    ) {}

    public function dashboard()
    {
        $items = ProductionItem::with('decision')->get();

        $ready = $items->filter(
            fn ($item) => $this->svc->ready($item)
        );

        return view('dashboard.index', [
            'total' => $items->count(),
            'ready' => $ready->count(),
            'review' => $items->filter(fn ($item) => !$this->svc->processed($item) && !$this->svc->approved($item))->count(),
            'custom' => $items->where('is_custom', true)->count(),
            'summary' => $this->svc->summary($ready),
            'batches' => PrintBatch::latest()->limit(5)->get(),
        ]);
    }

    public function syncBacklog()
    {
        try {
            $r = app(BacklogSyncService::class)->sync();
            return redirect()->route('dashboard')->with('ok', sprintf('Shopify backlog refreshed: %d current · %d added · %d updated · %d unchanged · %d matched · %d missing · %d already in print/completed, skipped.', $r['shopify_items'], $r['added'], $r['updated'], $r['unchanged'], $r['matched'], $r['missing'], $r['processed_skipped']));
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('dashboard')->with('error', 'Shopify backlog refresh failed: '.$e->getMessage());
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

        $batchIndex = $this->svc->batchIndex(true);
        $reviewSummary = ['approved'=>0, 'rejected'=>0, 'pending'=>0, 'in_print'=>0, 'completed'=>0, 'failed'=>0];
        foreach ($items as $item) {
            $item->production_batch = $batchIndex[$item->line_item_id] ?? null;
            $state = $item->production_batch
                ? match ($item->production_batch['status']) { 'PRINTED'=>'completed', 'FAILED'=>'failed', default=>'in_print' }
                : ($this->svc->approved($item) ? 'approved' : ($item->decision?->decision === 'WRONG' && $this->svc->current($item) ? 'rejected' : 'pending'));
            $item->workflow_state = $state;
            $reviewSummary[$state]++;
            $item->ready_for_print = $this->svc->ready($item);
            $item->approval_current = $this->svc->current($item);
        }

        return view('production.verification', compact('items', 'reviewSummary'));
    }

    public function verify(Request $request, ProductionItem $item)
    {
        $decision = $request->validate([
            'decision' => 'required|in:CORRECT,WRONG',
        ])['decision'];

        $this->svc->verify(
            $item,
            $decision,
            $request->user()->id
        );

        if ($request->expectsJson()) return $this->reviewResponse([$item->id]);

        return back()->with(
            'ok',
            $decision === 'CORRECT'
                ? 'Artwork approved.'
                : 'Artwork marked wrong.'
        );
    }

    public function bulkVerify(Request $request)
    {
        $data = $request->validate([
            'decision' => 'required|in:CORRECT,WRONG',
            'items' => 'required|array|min:1|max:500',
            'items.*' => 'required|integer|distinct|exists:production_items,id',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request) {
            $items = ProductionItem::whereIn('id', $data['items'])->lockForUpdate()->get();
            abort_if($items->count() !== count($data['items']), 422, 'Some selected orders are no longer available. Refresh and try again.');
            foreach ($items as $item) {
                abort_if($item->is_custom || !count($this->svc->ids($item)), 422,
                    'Only stock orders with matched Drive artwork can be verified in bulk.');
            }
            foreach ($items as $item) {
                $this->svc->verify($item, $data['decision'], $request->user()->id);
            }
        });

        if ($request->expectsJson()) return $this->reviewResponse($data['items']);

        return redirect()->route('production')->with('ok', count($data['items']).' orders '.
            ($data['decision'] === 'CORRECT' ? 'approved.' : 'rejected.'));
    }

    private function reviewResponse(array $ids)
    {
        $data = $this->verification()->getData();
        return response()->json([
            'items' => $data['items']->whereIn('id', $ids)->map(fn ($item) => [
                'id' => $item->id,
                'workflow' => $item->workflow_state,
                'ready' => (bool) $item->ready_for_print,
                'decision' => $item->decision?->decision,
            ])->values(),
            'summary' => $data['reviewSummary'],
            'workspace' => $this->svc->workspaceStats(true),
        ]);
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

    public function batch(Request $request)
    {
        $batch = $this->svc->batch(
            $request->user()->id
        );

        return redirect('/batches/'.$batch->id)
            ->with('ok', 'Production batch created.');
    }

    public function workspaceStatus()
    {
        return response()->json($this->svc->workspaceStats(true))->header('Cache-Control','no-store');
    }

    public function batches()
    {
        $query=PrintBatch::latest();
        if (request('stage') === 'active') $query->whereIn('status',['QUEUED','CLAIMED','DOWNLOADING','PREPARED','HANDOFF_REQUESTED','SENT_TO_RIP']);
        if (request('stage') === 'completed') $query->where('status','PRINTED');
        if (request('stage') === 'failed') $query->where('status','FAILED');
        return view('batches.index',['batches'=>$query->get()]);
    }

    public function showBatch(PrintBatch $batch)
    {
        return view('batches.show', compact('batch'));
    }

    /**
     * Operator requests the prepared batch to be handed
     * from the Factory Agent to Epson Edge Print.
     */
    public function requestEdgePrint(Request $request, PrintBatch $batch)
    {
        return $this->confirmPrintAction($request, $batch, 'HANDOFF', 'PREPARED', 'HANDOFF_REQUESTED');
    }

    public function markPrinted(Request $request, PrintBatch $batch)
    {
        return $this->confirmPrintAction($request, $batch, 'PRINTED', 'SENT_TO_RIP', 'PRINTED');
    }

    private function confirmPrintAction(Request $request, PrintBatch $batch, string $action, string $from, string $to)
    {
        $data = $request->validate([
            'signer_name'=>'required|string|min:2|max:100',
            'confirmation_phrase'=>'required|in:'.$action,
            'review_confirmed'=>'required|accepted',
            'confirmed_order_count'=>'required|integer|min:1',
            'confirmed_artwork_file_count'=>'required|integer|min:1',
            'payload_sha256'=>'required|string|size:64',
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request,$batch,$data,$action,$from,$to) {
            $locked = PrintBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === $from,422,'Batch state changed. Refresh and review this batch again.');
            $hash = hash('sha256',json_encode($locked->payload));
            abort_unless(hash_equals($hash,$data['payload_sha256']) &&
                (int)$data['confirmed_order_count'] === $locked->order_count &&
                (int)$data['confirmed_artwork_file_count'] === $locked->artwork_file_count,
                422,'Batch contents changed. Refresh and review the updated quantities.');
            \App\Models\BatchConfirmation::create([
                'print_batch_id'=>$locked->id,'user_id'=>$request->user()->id,
                'account_name'=>$request->user()->name,'signer_name'=>trim($data['signer_name']),
                'action'=>$action,'order_count'=>$locked->order_count,
                'total_sets'=>collect($locked->payload['orders'] ?? [])->sum('quantity'),
                'artwork_file_count'=>$locked->artwork_file_count,'payload_sha256'=>$hash,'confirmed_at'=>now(),
            ]);
            $locked->status=$to;
            if ($action === 'PRINTED') $locked->completed_at=now();
            $locked->save();
        });
        return redirect()->route('batch.show',$batch)->with('ok',
            $action === 'HANDOFF' ? 'Signed handoff recorded. Edge Print preparation requested; physical printing remains operator controlled.' : 'Signed physical print completion recorded.');
    }
}
