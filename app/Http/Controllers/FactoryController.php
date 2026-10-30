<?php
namespace App\Http\Controllers;

use App\Models\PrintBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FactoryController extends Controller
{
    public function next()
    {
        $batch = PrintBatch::whereIn('status', ['QUEUED','HANDOFF_REQUESTED'])->oldest()->first();
        if ($batch) {
            $batch->setAttribute('handoff_authorized', $batch->confirmations()->where('action','HANDOFF')->exists());
        }
        return response()->json(['ok'=>true,'batch'=>$batch]);
    }

    public function status(Request $request, PrintBatch $batch)
    {
        // Physical completion is exclusively a signed-in human action in the app.
        $state = $request->validate(['status'=>'required|in:CLAIMED,DOWNLOADING,PREPARED,SENT_TO_RIP,FAILED'])['status'];
        return DB::transaction(function () use ($batch, $state) {
            $locked = PrintBatch::lockForUpdate()->findOrFail($batch->id);
            $allowed = [
                'QUEUED'=>['CLAIMED'],
                'CLAIMED'=>['DOWNLOADING','FAILED'],
                'DOWNLOADING'=>['PREPARED','FAILED'],
                'HANDOFF_REQUESTED'=>['SENT_TO_RIP','FAILED'],
            ];
            abort_unless(in_array($state,$allowed[$locked->status] ?? [],true),409,'Batch state changed or transition is not permitted.');
            if ($state === 'SENT_TO_RIP') {
                abort_unless($locked->confirmations()->where('action','HANDOFF')->exists(),422,'Human handoff confirmation is missing.');
            }
            $locked->status = $state;
            if ($state === 'CLAIMED') $locked->claimed_at = now();
            $locked->save();
            return response()->json(['ok'=>true,'batch'=>$locked]);
        });
    }
}
