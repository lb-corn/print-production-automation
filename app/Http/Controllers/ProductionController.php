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
        // Only named local destinations are accepted; Overview keeps its existing behavior.
        $destination = request('return_to') === 'production' ? 'production' : 'dashboard';
        try {
            $r = app(BacklogSyncService::class)->sync();
            $response=redirect()->route($destination)->with('ok', sprintf('Shopify backlog refreshed: %d current · %d added · %d updated · %d unchanged · %d matched · %d missing · %d ambiguous · %d already in print/completed, skipped.', $r['shopify_items'], $r['added'], $r['updated'], $r['unchanged'], $r['matched'], $r['missing'], $r['ambiguous'], $r['processed_skipped']));
            if(!($r['artwork_schema_ready']??true))$response->with('warning','Orders synced and artwork checked. The server still needs the artwork_review database migration before artwork approval and printing. You do not need to fix the whole catalog.');
            return $response;
        } catch (\Throwable $e) {
            if ($e instanceof \App\Exceptions\ArtworkSchemaNotReady) return back()->with('error', $e->getMessage());
            report($e);
            return redirect()->route($destination)->with('error', 'Shopify backlog refresh failed. No changes were saved. Check the integration logs and try again.');
        }
    }

    public function rematchArtwork(Request $request)
    {
        $request->validate(['confirm_rematch'=>'required|accepted']);
        try {
            \App\Services\ArtworkRematchService::ensureSchemaReady();
            $counts = app(\App\Services\ArtworkRematchService::class)->rematch();
            return redirect()->route('production')->with('ok', sprintf(
                'Artwork rematched: %d matched · %d missing · %d ambiguous · %d skipped. Review artwork before approval.',
                $counts['matched'], $counts['missing'], $counts['ambiguous'], $counts['skipped']));
        } catch (\Throwable $e) {
            if ($e instanceof \App\Exceptions\ArtworkSchemaNotReady) return back()->with('error', $e->getMessage());
            report($e);
            return redirect()->route('production')->with('error', 'Artwork rematch failed. No changes were saved. Check the integration logs and try again.');
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
            $validArtwork = \App\Services\ArtworkMatcherService::validSelection($item);
            if (!$item->artwork_review && !$item->production_batch && !$item->is_custom) {
                // Explain legacy selections without constructing an integration client or saving data.
                $expectations = (new \App\Services\ArtworkMatcherService())->match($item->toArray(), [])['artwork_review'];
                unset($expectations['policy']);
                $expectations['lookup_status'] = 'not_checked';
                $expectations['issues'] = ['Run Rematch Artwork to check current Drive files.'];
                $item->artwork_review = $expectations;
            }
            $item->display_matches = $item->production_batch || $validArtwork ? ($item->matches ?? [])
                : (new \App\Services\ArtworkMatcherService())->savedPreviews($item->toArray(), $item->matches ?? []);
            $item->valid_artwork = $validArtwork;
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
            'factoryReadiness'=>app(\App\Services\FactoryDispatchService::class)->readiness(),
            'factoryQueueAvailable'=>app(\App\Services\FactoryDispatchService::class)->consentReady(),
            'materialGroups'=>$items->groupBy(fn($item)=>\App\Services\PrintLabelService::group($item)),
        ]);
    }

    public function batch(Request $request)
    {
        if(!app(\App\Services\FactoryDispatchService::class)->consentReady())return redirect()->route('queue')->with('error','Printing is temporarily unavailable until an administrator completes the server database update. Your approved items have not been queued.');
        $data=$request->validate(['items'=>'required|array|min:1|max:1000','items.*'=>'required|integer|distinct','dispatch_confirmed'=>'required|accepted']);
        $batch=\Illuminate\Support\Facades\DB::transaction(function()use($request,$data){
            $batch=$this->svc->batch($request->user()->id,$data['items']);
            app(\App\Services\FactoryDispatchService::class)->request($batch);return $batch;
        });

        return redirect('/batches/'.$batch->id)
            ->with('ok', 'Queue request recorded. Track preparation and waiting reasons here. This is not a physical-print confirmation.');
    }

    public function workspaceStatus()
    {
        return response()->json($this->svc->workspaceStats(true))->header('Cache-Control','no-store');
    }

    public function batches()
    {
        $query=PrintBatch::latest();
        if (request('stage') === 'active') $query->whereIn('status',['QUEUED','CLAIMED','DOWNLOADING','PREPARED','HANDOFF_REQUESTED','DELIVERING','SENT_TO_RIP']);
        if (request('stage') === 'completed') $query->where('status','PRINTED');
        if (request('stage') === 'failed') $query->where('status','FAILED');
        return view('batches.index',['batches'=>$query->get()]);
    }

    public function showBatch(PrintBatch $batch)
    {
        $itemIds=ProductionItem::whereIn('line_item_id',array_column($batch->payload['orders']??[],'line_item_id'))->pluck('id','line_item_id')->all();
        $factoryProgress=app(\App\Services\FactoryDispatchService::class)->progress($batch);
        return view('batches.show', compact('batch','itemIds','factoryProgress'));
    }
    public function batchProgress(PrintBatch $batch){return response()->json(app(\App\Services\FactoryDispatchService::class)->progress($batch))->header('Cache-Control','no-store');}
    public function requestPreparation(Request $request,PrintBatch $batch){
        if(!app(\App\Services\FactoryDispatchService::class)->consentReady())return redirect()->route('batch.show',$batch)->with('error','Automatic preparation is unavailable until an administrator completes the server database update. No preparation request was saved.');
        $request->validate(['dispatch_confirmed'=>'required|accepted']);
        \Illuminate\Support\Facades\DB::transaction(fn()=>app(\App\Services\FactoryDispatchService::class)->request($batch));
        return redirect()->route('batch.show',$batch)->with('ok','Automatic preparation requested. The batch stays pending until an enabled listener can process it.');
    }

    /**
     * Operator requests the prepared batch to be handed
     * from the Factory Agent to Epson Edge Print.
     */
    public function returnItemToReview(Request $request, PrintBatch $batch, ProductionItem $item)
    {
        abort_unless($request->user() && $request->user()->role!=='demo',403);
        $request->validate(['return_confirmed'=>'required|accepted']);
        app(\App\Services\BatchReturnService::class)->returnItemToReview($batch,$item,$request->user()->id);
        return redirect()->route('production')->with('ok','Selected item returned to artwork review. Other items remain queued. Rematch and approve this item again.');
    }

    public function returnBatchToReview(Request $request, PrintBatch $batch)
    {
        abort_unless($request->user() && $request->user()->role!=='demo',403);
        $request->validate(['return_confirmed'=>'required|accepted']);
        return redirect()->route('batch.show',$batch)->with('error','Choose Return this item next to the item you want to review. Whole-batch return is no longer available.');
    }

    public function requestEdgePrint(Request $request, PrintBatch $batch)
    {
        if(app(\App\Services\FactoryDispatchService::class)->ready() && $batch->automation_requested_at)abort_if(collect($batch->payload['orders']??[])->contains(fn($row)=>(int)($row['quantity']??0)!==1),422,'Automatic hot-folder delivery supports quantity one only. Manual quantity handling is required.');
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
            if($action==='HANDOFF' && $locked->automation_requested_at)app(\App\Services\FactoryDispatchService::class)->authorizeJob($locked);
            if($action==='HANDOFF')app(\App\Services\FactoryDispatchService::class)->assertHandoffAllowed($locked);
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
