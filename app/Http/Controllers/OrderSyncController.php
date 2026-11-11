<?php
namespace App\Http\Controllers;

use App\Services\OrderSyncService;
use Illuminate\Http\Request;

class OrderSyncController extends Controller
{
    public function store(Request $request, OrderSyncService $sync)
    {
        $areas = array_values(array_filter(['bags','apparel'], fn($area) => $request->user()->allows($area.'.sync.edit')));
        abort_unless($areas, 403);
        $area = $request->input('area') === 'apparel' ? 'apparel' : 'bags';
        if (!$request->user()->allows($area.'.overview.view')) $area = $request->user()->allows('bags.overview.view') ? 'bags' : 'apparel';
        try {
            $run = $sync->syncPartners('manual', $areas,\App\Services\PartnerContext::allowed($request->user()));
            return redirect()->route('dashboard', ['area'=>$area])->with($run['state'] === 'success' ? 'ok' : 'warning',
                $run['state'] === 'success' ? 'Order sync complete. Results for each production area are shown below.' : 'Order sync needs attention. Review the separate results below.');
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('dashboard', ['area'=>$area])->with('error', 'Order sync could not start. Another sync may be running; wait a few minutes and retry.');
        }
    }
}
