<?php

namespace App\Http\Controllers;

use App\Services\Shopify\JerseyOrderSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ShopifySyncController extends Controller
{
    public function store(JerseyOrderSyncService $sync): RedirectResponse
    {
        $lock = Cache::lock('shopify:sync-jerseys:web', 600);

        if (! $lock->get()) {
            return back()->with('error', 'A sync is already running. Try again in a few minutes.');
        }

        try {
            $stats = $sync->sync(full: false, source: 'manual');

            return back()->with('ok', sprintf(
                'Sync complete: %d pages, %d orders scanned, %d jersey items matched, %d inserted, %d updated, %d skipped.',
                $stats['pages_fetched'],
                $stats['shopify_orders_scanned'],
                $stats['jersey_line_items_matched'],
                $stats['inserted'],
                $stats['updated'],
                $stats['skipped'],
            ));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Sync failed. Check the application logs for details.');
        } finally {
            $lock->release();
        }
    }
}
