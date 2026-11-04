<?php
require __DIR__.'/agent-updater.php';
$releases=app(App\Services\FactoryAgentReleaseService::class)->releases();
$check(count($releases)===5 && $releases[0]['version']===json_decode(file_get_contents(resource_path('factory-agent-release.json')),true)['version'],'Release history shows the five real packages with the newest first');
$r->setUserResolver(fn()=>$user);
foreach($releases as $release){
    $r->query->set('version',$release['version']);
    $package=$monitor->portalPackage($r);
    $check(hash_file('sha256',$package->getFile()->getPathname())===$release['sha256'],'Versioned download returns its exact original package: '.$release['version']);
}
foreach(['../../.env','9.9.9'] as $missing){$r->query->set('version',$missing);$reject(fn()=>$monitor->portalPackage($r),404);}
$r->setUserResolver(fn()=>$ordinary);$reject(fn()=>$monitor->portalPackage($r),403);
$snapshot=['ready'=>true,'server_time'=>now()->toIso8601String(),'offline_after_seconds'=>180,'errors'=>[], 'agents'=>[['agent_id'=>'8dd683f4-95d5-4940-9ae7-ad3b45bae02e','online'=>true,'last_seen_at'=>now()->toIso8601String(),'handoff_control_ready'=>true,'handoff_blocked'=>false,'telemetry'=>['agent_version'=>'1.4.0','reporter_mode'=>'listener','batch_id'=>null,'stage'=>'idle','source_sha256'=>str_repeat('a',64),'python_version'=>'3.13.0','windows_version'=>'10.0.26200']]]];
auth()->setUser($user);view()->share('errors',new Illuminate\Support\ViewErrorBag);
$html=view('admin.settings',['section'=>'agent','monitoring'=>$snapshot,'agentReleases'=>$releases,'agentPackage'=>json_decode(file_get_contents(resource_path('factory-agent-release.json')),true)])->render();
$check(substr_count($html,'class="agent-release-row"')===5 && substr_count($html,'>Download updater</a>')===1,'Five compact release rows offer one current updater and individual versioned ZIPs');
if(in_array('--preview',$argv,true)){
    file_put_contents(__DIR__.'/../tmp/agent-releases-preview.html',$html);
    file_put_contents(__DIR__.'/../tmp/agent-releases-snapshot.json',json_encode($snapshot));
}
