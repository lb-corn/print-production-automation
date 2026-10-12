<?php

namespace App\Http\Controllers;

use App\Models\PrintBatch;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FactoryController extends Controller
{
    public function __construct(private ProductionService $svc) {}

    public function preflight()
    {
        return response()->json([
            'ok' => true,
            'service' => 'Lucky Bags Production Factory API',
            'time' => now()->toIso8601String(),
            'contract' => [
                'claim_endpoint' => '/api/factory/batches/next',
                'status_endpoint' => '/api/factory/batches/{batch}/status',
                'operator_print_confirmation_required' => true,
                'physical_print_auto_complete' => false,
            ],
        ]);
    }

    public function next()
    {
        $batch = DB::transaction(function () {
            $staleBefore = now()->subMinutes((int) env('FACTORY_CLAIM_RETRY_MINUTES', 30));

            $batch = PrintBatch::where(function ($query) use ($staleBefore) {
                $query->whereIn('status', ['QUEUED', 'HANDOFF_REQUESTED'])
                    ->orWhere(function ($inner) use ($staleBefore) {
                        $inner->whereIn('status', ['CLAIMED', 'DOWNLOADING'])
                            ->where('updated_at', '<=', $staleBefore);
                    });
            })
                ->oldest()
                ->lockForUpdate()
                ->first();

            if ($batch && $batch->status === 'QUEUED') {
                $batch->status = 'CLAIMED';
                $batch->claimed_at = $batch->claimed_at ?: now();
                $batch->save();
            }

            return $batch?->fresh();
        });

        return response()->json([
            'ok' => true,
            'batch' => $batch,
        ]);
    }

    public function status(Request $request, PrintBatch $batch)
    {
        $data = $request->validate([
            'status' => 'required|in:CLAIMED,DOWNLOADING,PREPARED,SENT_TO_RIP,FAILED',
            'failure_reason' => 'nullable|string|max:500',
        ]);

        $batch = $this->svc->transition(
            $batch,
            $data['status'],
            $data['failure_reason'] ?? null
        );

        return response()->json([
            'ok' => true,
            'batch' => $batch,
        ]);
    }
}
