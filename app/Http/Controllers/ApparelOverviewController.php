<?php
namespace App\Http\Controllers;

use App\Models\JerseyOrder;

class ApparelOverviewController extends Controller
{
    public function index()
    {
        $open = JerseyOrder::needsAttention('open');
        $summary = [
            'unfulfilled'=>JerseyOrder::eligibleForProduction()->where('shopify_fulfillment_status','UNFULFILLED')->count(),
            'partially_fulfilled'=>JerseyOrder::eligibleForProduction()->where('shopify_fulfillment_status','PARTIALLY_FULFILLED')->count(),
            'fulfilled'=>JerseyOrder::eligibleForProduction()->where('shopify_fulfillment_status','FULFILLED')->count(),
            'total'=>JerseyOrder::count(),
            'pending_remarks'=>JerseyOrder::needsAttention('pending_remarks')->count(),
            'missing_tracking'=>JerseyOrder::needsAttention('missing_tracking')->count(),
            'open_lines'=>(clone $open)->count(),
            'open_units'=>(int)(clone $open)->sum('quantity'),
        ];
        return view('dashboard.apparel', [
            'charts'=>app(\App\Services\DashboardCharts::class)->apparel($summary),
            'summary'=>$summary,
            'attentionOrders'=>(clone $open)->orderByRaw('CASE WHEN ordered_at IS NULL THEN 1 ELSE 0 END')->orderBy('ordered_at')->orderBy('id')->limit(6)->get(),
            'pendingRemarks'=>JerseyOrder::needsAttention('pending_remarks')->orderBy('remark_updated_at')->orderBy('id')->limit(3)->get(),
        ]);
    }
}
