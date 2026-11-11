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

    private string $constructedPartner;
    public function __construct(private BacklogSyncService $bags, private JerseyOrderSyncService $apparel) {$this->constructedPartner=PartnerContext::current();}

    public function sync(string $source = 'command', array $areas = ['bags', 'apparel']): array
    {
        $areas = array_values(array_intersect(['bags', 'apparel'], $areas));
        if (!$areas) throw new \InvalidArgumentException('No order sync access.');
        $lock = Cache::lock(PartnerContext::key(self::LOCK_KEY), 1800);
        if (!$lock->get()) throw new \RuntimeException('An order sync is already running.');
        try {
            $source = in_array($source, ['scheduled', 'manual', 'command'], true) ? $source : 'command';
            $previous = Cache::get(PartnerContext::key(self::STATUS_KEY), []);
            $run = ['state'=>'running', 'source'=>$source, 'started_at'=>now()->toIso8601String(), 'finished_at'=>null, 'areas'=>$previous['areas'] ?? []];
            foreach ($areas as $area) $run['areas'][$area] = ['state'=>'pending', 'last_success_at'=>$previous['areas'][$area]['last_success_at'] ?? null];
            if ($source === 'scheduled') Cache::forever(PartnerContext::key(self::SCHEDULE_KEY), $run['started_at']);
            Cache::forever(PartnerContext::key(self::STATUS_KEY), $run);
            foreach ($areas as $area) {
                $run['areas'][$area]['state'] = 'running';
                Cache::forever(PartnerContext::key(self::STATUS_KEY), $run);
                try {
                    $stats = $area === 'bags' ? $this->bags->sync() : $this->apparel->sync(full:false, source:$source);
                    $run['areas'][$area] = ['state'=>'success', 'last_success_at'=>now()->toIso8601String(), 'stats'=>$stats];
                    if($area==='bags' && !($stats['material_config_ready']??true))$run['areas'][$area]['warning']='Orders imported. Complete Bag Code values in the Vibe material tab, then sync again to match artwork.';
                    if ($area === 'bags' && !($stats['artwork_schema_ready'] ?? true)) $run['areas'][$area]['warning'] = 'Orders synced. Artwork approval needs the database update.';
                } catch (\Throwable $e) {
                    report($e);
                    $run['areas'][$area]['state'] = 'error';
                    $run['areas'][$area]['message'] = 'Sync failed. Check integration settings and application logs, then retry.';
                }
                Cache::forever(PartnerContext::key(self::STATUS_KEY), $run);
            }
            $successes = count(array_filter($areas, fn($area) => $run['areas'][$area]['state'] === 'success'));
            $run['state'] = $successes === count($areas) ? 'success' : ($successes ? 'partial' : 'error');
            $run['finished_at'] = now()->toIso8601String();
            Cache::forever(PartnerContext::key(self::STATUS_KEY), $run);
            return $run;
        } finally {
            $lock->release();
        }
    }

    public function syncPartners(string $source='command',array $areas=['bags','apparel'],?array $partners=null):array {
        $partners??=array_keys(config('services.partners',[]));$runs=[];
        foreach($partners as $partner){
            if(!config('services.partners.'.$partner.'.enabled',false))continue;
            $runs[$partner]=PartnerContext::run($partner,function()use($partner,$source,$areas){
                try{return ($partner===$this->constructedPartner?$this:app(self::class))->sync($source,$areas);}
                catch(\Throwable $e){report($e);$run=['state'=>'error','source'=>$source,'started_at'=>now()->toIso8601String(),'finished_at'=>now()->toIso8601String(),'areas'=>[]];foreach($areas as $area)$run['areas'][$area]=['state'=>'error','message'=>'Integration setup needs attention.'];Cache::forever(PartnerContext::key(self::STATUS_KEY),$run);return $run;}
            });
        }
        $states=array_column($runs,'state');
        return ['state'=>!$states?'error':(count(array_filter($states,fn($s)=>$s==='success'))===count($states)?'success':(array_intersect($states,['success','partial'])?'partial':'error')),'partners'=>$runs];
    }

    public static function status(): array
    {
        if(PartnerContext::current()==='all'){
            $runs=[];foreach(PartnerContext::visible() as $partner)$runs[]=PartnerContext::run($partner,fn()=>self::status());
            $result=$runs[0]??['state'=>'never','areas'=>[]];$states=array_column($runs,'state');
            $result['state']=in_array('running',$states,true)?'running':(count(array_unique($states))===1?($states[0]??'never'):'partial');
            $result['areas']=[];
            foreach(['bags','apparel'] as $area){$parts=array_column(array_column($runs,'areas'),$area);$stats=[];foreach($parts as $part)foreach($part['stats']??[] as $key=>$value)if(is_numeric($value))$stats[$key]=($stats[$key]??0)+$value;
                $areaStates=array_column($parts,'state');$result['areas'][$area]=['state'=>count($parts)===count($runs) && count(array_filter($areaStates,fn($state)=>$state==='success'))===count($runs)?'success':'pending','stats'=>$stats,'last_success_at'=>collect($parts)->pluck('last_success_at')->filter()->sort()->last()];
            }
            $result['finished_at']=collect($runs)->pluck('finished_at')->filter()->sort()->last();
            return $result;
        }
        $run = Cache::get(PartnerContext::key(self::STATUS_KEY), ['state'=>'never', 'areas'=>[]]);
        if ($run['state'] === 'running' && Carbon::parse($run['started_at'])->lt(now()->subMinutes(30))) {
            $run['state'] = 'interrupted';
            foreach ($run['areas'] as &$area) if (in_array($area['state'], ['pending','running'], true)) $area['state'] = 'interrupted';
            unset($area);
        }
        $next = now()->startOfMinute();
        $next->addMinutes(self::INTERVAL_MINUTES - ($next->minute % self::INTERVAL_MINUTES));
        $scheduled = Cache::get(PartnerContext::key(self::SCHEDULE_KEY));
        return $run + ['next_expected_at'=>$next->toIso8601String(), 'last_scheduled_at'=>$scheduled,
            'schedule_health'=>!$scheduled ? 'unconfirmed' : (Carbon::parse($scheduled)->lt(now()->subMinutes(20)) ? 'overdue' : 'recent')];
    }
}
