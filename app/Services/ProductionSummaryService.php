<?php
namespace App\Services;

use App\Models\JerseyOrder;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ProductionSummaryService
{
    private array $snapshots = [];

    public function forUser(User $user, bool $refresh = false): array
    {
        $bags = $user->allows('bags.overview.view');
        $apparel = $user->allows('apparel.overview.view');
        $key = PartnerContext::current().':'.(int) $bags . ':' . (int) $apparel;
        if (!$refresh && isset($this->snapshots[$key])) return $this->snapshots[$key];
        $data = ['areas' => []];
        if ($bags) {
            $stats = app(ProductionService::class)->workspaceStats($refresh);
            $data += $stats; // Preserve existing bag status consumers.
            $data['areas']['bags'] = ['ready' => $stats['ready'], 'active' => $stats['in_print'], 'done' => $stats['completed']];
        }
        if ($apparel) {
            $counts = Schema::hasTable('jersey_orders')
                ? JerseyOrder::eligibleForProduction()->selectRaw('shopify_fulfillment_status, COUNT(*) as total')->groupBy('shopify_fulfillment_status')->pluck('total', 'shopify_fulfillment_status')->all()
                : null;
            $data['areas']['apparel'] = $counts === null
                ? ['waiting' => null, 'active' => null, 'done' => null]
                : ['waiting' => array_sum($counts) - (int) ($counts['FULFILLED'] ?? 0) - (int) ($counts['PARTIALLY_FULFILLED'] ?? 0), 'active' => (int) ($counts['PARTIALLY_FULFILLED'] ?? 0), 'done' => (int) ($counts['FULFILLED'] ?? 0)];
        }
        return $this->snapshots[$key] = $data;
    }
}
