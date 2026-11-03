<?php
namespace App\Services;

use App\Models\FactoryAgent;
use Illuminate\Support\Facades\{DB,Schema,Validator};
use Illuminate\Validation\Rule;

class FactoryMonitoringService {
    public const OPERATIONS=['startup','poll','prepare','handoff','diagnostic','configuration','shutdown'];
    public const ERRORS=['configuration','api_authentication','api_unreachable','drive_unreachable','defender_unavailable','workspace_unavailable','handoff_unavailable','batch_operation_failed','diagnostic_failed','unknown'];
    public const ERROR_LABELS=['configuration'=>'Local configuration check failed.','api_authentication'=>'Factory API authentication failed. Verify the same key on server and PC.','api_unreachable'=>'Factory API could not be reached.','drive_unreachable'=>'Read-only Drive diagnostic did not connect.','defender_unavailable'=>'Defender diagnostic did not pass.','workspace_unavailable'=>'Workspace access check did not pass.','handoff_unavailable'=>'Hot-folder access check did not pass.','batch_operation_failed'=>'Batch operation failed. Inspect the batch and local logs before retrying.','diagnostic_failed'=>'A local diagnostic failed.','unknown'=>'Agent reported an error; inspect the local logs.'];
    public function ready():bool{return Schema::hasTable('factory_agents');}
    public function release():array {
        $path=resource_path('factory-agent-release.json');
        return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['version'=>null,'factory_agent_sha256'=>null];
    }
    public function accept(array $input):FactoryAgent {
        $allowed=['agent_id','agent_version','source_sha256','python_version','windows_version','sampled_at_ms','process_status','workspace_exists','hot_folder_exists','credentials_present','diagnostics','last_success','last_failure','batch_id','stage'];
        abort_if(array_diff(array_keys($input),$allowed),422,'Unsupported telemetry fields. Do not send secrets, paths or customer data.');
        $rules=[
            'agent_id'=>'required|uuid','agent_version'=>['required','string','regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D'],
            'source_sha256'=>['required','string','regex:/^[a-f0-9]{64}$/D'],
            'python_version'=>['required','string','regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D'],
            'windows_version'=>['required','string','regex:/^\d{1,6}(?:\.\d{1,6}){1,3}$/D'],
            'sampled_at_ms'=>'required|integer|min:1','process_status'=>['required',Rule::in(['running','checking','stopping','error'])],
            'workspace_exists'=>'required|boolean','hot_folder_exists'=>'required|boolean','credentials_present'=>'required|boolean',
            'batch_id'=>'nullable|integer|min:1','stage'=>['required',Rule::in(['idle','validating','downloading','prepared','handoff','sent_to_rip','error','stopped'])],
            'diagnostics'=>'sometimes|array:checked_at_ms,workspace_writable,hot_folder_writable,defender_enabled,defender_realtime,defender_scan,drive_connected,error_code',
            'diagnostics.checked_at_ms'=>'required_with:diagnostics|integer|min:1',
            'diagnostics.error_code'=>['nullable',Rule::in(self::ERRORS)],
            'last_success'=>'sometimes|array:kind,at_ms','last_failure'=>'sometimes|array:kind,at_ms,error_code',
        ];
        foreach(['workspace_writable','hot_folder_writable','defender_enabled','defender_realtime','defender_scan','drive_connected'] as $key)$rules['diagnostics.'.$key]='required_with:diagnostics|nullable|boolean';
        foreach(['last_success','last_failure'] as $key){$rules[$key.'.kind']=['required_with:'.$key,Rule::in(self::OPERATIONS)];$rules[$key.'.at_ms']='required_with:'.$key.'|integer|min:1';}
        $rules['last_failure.error_code']=['required_with:last_failure',Rule::in(self::ERRORS)];
        $data=Validator::make($input,$rules)->validate();$data['agent_id']=strtolower($data['agent_id']);$data['sampled_at_ms']=(int)$data['sampled_at_ms'];
        // Even a well-shaped hash/UUID must never be used to smuggle the actual bearer key.
        $secret=(string)config('services.factory.api_key');
        if(strlen($secret)>=32)foreach([$data['source_sha256'],$data['agent_id']] as $value)abort_if(strcasecmp($value,$secret)===0,422,'Telemetry must not contain credentials.');
        foreach(['workspace_exists','hot_folder_exists','credentials_present'] as $key)$data[$key]=(bool)$data[$key];
        foreach(['workspace_writable','hot_folder_writable','defender_enabled','defender_realtime','defender_scan','drive_connected'] as $key)if(isset($data['diagnostics'][$key]))$data['diagnostics'][$key]=(bool)$data['diagnostics'][$key];
        $now=now();$nowMs=(int)$now->getTimestampMs();
        abort_if($data['sampled_at_ms']<$nowMs-60000 || $data['sampled_at_ms']>$nowMs+15000,422,'Telemetry time is stale or ahead of the server. Synchronize the PC clock.');
        foreach(['diagnostics.checked_at_ms','last_success.at_ms','last_failure.at_ms'] as $key)abort_if(data_get($data,$key,$nowMs)>$nowMs+15000,422,'Diagnostic/activity time is ahead of the server.');
        return DB::transaction(function()use($data,$now){
            $agent=FactoryAgent::where('agent_id',$data['agent_id'])->lockForUpdate()->first();
            abort_if($agent && $data['sampled_at_ms']<=$agent->sampled_at_ms,409,'Duplicate or replayed telemetry was not accepted.');
            abort_if(!$agent && FactoryAgent::count()>=20,422,'Agent monitoring is limited to 20 identifiers. Contact the administrator.');
            $previous=$agent?->telemetry??[];$activity=$agent?->activity??[];
            // A periodic worker must not overwrite a newer explicit diagnostic from the same PC.
            foreach(['diagnostics'=>'checked_at_ms','last_success'=>'at_ms','last_failure'=>'at_ms'] as $key=>$clock) {
                if(isset($data[$key],$previous[$key]) && $data[$key][$clock]<=$previous[$key][$clock])unset($data[$key]);
            }
            $event=['at'=>$now->toIso8601String(),'stage'=>$data['stage'],'process_status'=>$data['process_status'],'batch_id'=>$data['batch_id']??null];
            foreach(['last_success','last_failure'] as $key)if(isset($data[$key]) && ($data[$key]!==($previous[$key]??null)))$event[$key]=$data[$key];
            if(isset($data['diagnostics']) && ($data['diagnostics']['checked_at_ms']!==($previous['diagnostics']['checked_at_ms']??null)))$event['diagnostic']=true;
            if(!$agent || count($event)>4 || ($previous['stage']??null)!==$data['stage'] || ($previous['process_status']??null)!==$data['process_status'] || ($previous['batch_id']??null)!==($data['batch_id']??null)){$activity[]=$event;$activity=array_slice($activity,-20);}
            $agent??=new FactoryAgent(['agent_id'=>$data['agent_id']]);
            $agent->fill(['sampled_at_ms'=>$data['sampled_at_ms'],'last_seen_at'=>$now,'telemetry'=>array_replace($previous,$data),'activity'=>$activity])->save();
            return $agent;
        });
    }
    public function snapshot():array {
        $release=$this->release();$ready=$this->ready();$threshold=max(30,min(600,(int)config('services.factory.offline_after_seconds',120)));
        $agents=$ready?FactoryAgent::orderByDesc('last_seen_at')->limit(20)->get()->map(function($agent)use($threshold,$release){
            $t=$agent->telemetry;$online=$agent->last_seen_at->getTimestamp()>now()->timestamp-$threshold && ($t['process_status']??'')!=='stopping';
            $diagnosticFresh=$online && (int)($t['diagnostics']['checked_at_ms']??0)>=now()->getTimestampMs()-600000;
            return ['agent_id'=>$agent->agent_id,'online'=>$online,'last_seen_at'=>$agent->last_seen_at->toIso8601String(),'telemetry'=>$t,'diagnostics_fresh'=>$diagnosticFresh,
                'hash_matches_release'=>($t['agent_version']??null)===$release['version']?hash_equals((string)$release['factory_agent_sha256'],(string)($t['source_sha256']??'')):null,
                'activity'=>array_reverse($agent->activity)];
        })->values()->all():[];
        return ['ready'=>$ready,'agents'=>$agents,'release'=>$release,'offline_after_seconds'=>$threshold,'server_time'=>now()->toIso8601String(),'errors'=>self::ERROR_LABELS];
    }
    public function connection():array {
        if(!$this->ready())return ['state'=>'unavailable','online'=>0];
        $threshold=max(30,min(600,(int)config('services.factory.offline_after_seconds',120)));
        $agents=FactoryAgent::select(['last_seen_at','telemetry'])->limit(20)->get();
        $connected=$agents->filter(fn($agent)=>$agent->last_seen_at->getTimestamp()>now()->timestamp-$threshold && ($agent->telemetry['process_status']??'')!=='stopping');$online=$connected->count();
        $expires=$online?max(0,$connected->max(fn($agent)=>$agent->last_seen_at->getTimestamp())+$threshold-now()->timestamp):0;
        return ['state'=>$online?'online':($agents->isEmpty()?'never_connected':'offline'),'online'=>$online,'expires_in_seconds'=>$expires];
    }
}
