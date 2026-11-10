<?php
namespace App\Services;

use App\Services\Shopify\JerseyOrderSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class OrderSyncService
{
    public const STATUS_KEY = 'production.orders.sync.status';
    public const LOCK_KEY = 'production.orders.sync';
    public const SCHEDULE_KEY = 'production.orders.sync.scheduled_at';
    public const INTERVAL_MINUTES = 10;

    public function __construct(private BacklogSyncService $bags, private JerseyOrderSyncService $apparel) {}

    public function sync(string $source = 'command', array $areas = ['bags', 'apparel']): array
    {
        $areas = array_values(array_intersect(['bags', 'apparel'], $areas));
        if (!$areas) throw new \InvalidArgumentException('No order sync access.');
        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (!$lock->get()) throw new \RuntimeException('An order sync is already running.');
        try {
            $source = in_array($source, ['scheduled', 'manual', 'command'], true) ? $source : 'command';
            $previous = Cache::get(self::STATUS_KEY, []);
            $run = ['state'=>'running', 'source'=>$source, 'started_at'=>now()->toIso8601String(), 'finished_at'=>null, 'areas'=>$previous['areas'] ?? []];
            foreach ($areas as $area) $run['areas'][$area] = ['state'=>'pending', 'last_success_at'=>$previous['areas'][$area]['last_success_at'] ?? null];
            if ($source === 'scheduled') Cache::forever(self::SCHEDULE_KEY, $run['started_at']);
            Cache::forever(self::STATUS_KEY, $run);
            foreach ($areas as $area) {
                $run['areas'][$area]['state'] = 'running';
                Cache::forever(self::STATUS_KEY, $run);
                try {
                    $stats = $area === 'bags' ? $this->bags->sync() : $this->apparel->sync(full:false, source:$source);
                    $run['areas'][$area] = ['state'=>'success', 'last_success_at'=>now()->toIso8601String(), 'stats'=>$stats];
                    if ($area === 'bags' && !($stats['artwork_schema_ready'] ?? true)) $run['areas'][$area]['warning'] = 'Orders synced. Artwork approval needs the database update.';
                } catch (\Throwable $e) {
                    report($e);
                    $run['areas'][$area]['state'] = 'error';
                    $run['areas'][$area]['message'] = 'Sync failed. Check integration settings and application logs, then retry.';
                }
                Cache::forever(self::STATUS_KEY, $run);
            }
            $successes = count(array_filter($areas, fn($area) => $run['areas'][$area]['state'] === 'success'));
            $run['state'] = $successes === count($areas) ? 'success' : ($successes ? 'partial' : 'error');
            $run['finished_at'] = now()->toIso8601String();
            Cache::forever(self::STATUS_KEY, $run);
            return $run;
        } finally {
            $lock->release();
        }
    }

    public static function status(): array
    {
        $run = Cache::get(self::STATUS_KEY, ['state'=>'never', 'areas'=>[]]);
        if ($run['state'] === 'running' && Carbon::parse($run['started_at'])->lt(now()->subMinutes(30))) {
            $run['state'] = 'interrupted';
            foreach ($run['areas'] as &$area) if (in_array($area['state'], ['pending','running'], true)) $area['state'] = 'interrupted';
            unset($area);
        }
        $next = now()->startOfMinute();
        $next->addMinutes(self::INTERVAL_MINUTES - ($next->minute % self::INTERVAL_MINUTES));
        $scheduled = Cache::get(self::SCHEDULE_KEY);
        return $run + ['next_expected_at'=>$next->toIso8601String(), 'last_scheduled_at'=>$scheduled,
            'schedule_health'=>!$scheduled ? 'unconfirmed' : (Carbon::parse($scheduled)->lt(now()->subMinutes(20)) ? 'overdue' : 'recent')];
    }
}
