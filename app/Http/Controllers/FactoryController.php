<?php

namespace App\Http\Controllers;

use App\Models\PrintBatch;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FactoryController extends Controller
{
    public function __construct(private ProductionService $svc) {}

    public function next()
    {
        $batch = DB::transaction(function () {
            $batch = PrintBatch::whereIn('status', ['QUEUED', 'HANDOFF_REQUESTED'])
                ->oldest()
                ->lockForUpdate()
                ->first();

            if ($batch && $batch->status === 'QUEUED') {
                $batch->status = 'CLAIMED';
                $batch->claimed_at = $batch->claimed_at ?: now();
                $batch->save();

                $batch->status = 'QUEUED';
            }

            return $batch;
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
