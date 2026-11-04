<?php
require __DIR__.'/factory-dispatch.php';
use Illuminate\Support\Facades\URL;
config(['app.url'=>'https://portal.example.test']);URL::forceRootUrl('https://portal.example.test');
$monitor=new App\Http\Controllers\FactoryMonitoringController();$r=Illuminate\Http\Request::create('https://portal.example.test/admin/factory-agent/updater');$r->setUserResolver(fn()=>$user);
$response=$monitor->updater($r);$body=$response->getContent();$version=json_decode(file_get_contents(resource_path('factory-agent-release.json')),true)['version'];
$check(str_contains($response->headers->get('Content-Disposition'),'Update-LuckyBags-Agent-v'.$version.'.cmd') && str_starts_with($body,'@echo off'),'Admin downloads one versioned double-click updater');
$check(!str_contains($body,'__PACKAGE_') && str_contains($body,"C:\\LuckyBagsFactory") && str_contains($body,'LUCKYBAGS_UPDATE_SELF'),'Updater runs from its download location and targets the existing C drive installation');
preg_match('/\x24PackageUrl=\x27([^\x27]+)\x27/',$body,$match);$url=$match[1]??'';
$download=Illuminate\Http\Request::create($url);$middleware=new Illuminate\Routing\Middleware\ValidateSignature();
$package=$middleware->handle($download,fn($request)=>$monitor->signedPackage($request,$version),'relative');
$check($package->getFile()->getRealPath()===realpath(resource_path('factory-agent-install.zip')),'A valid time-limited link downloads only the verified public package without an Admin browser session');
$bad=Illuminate\Http\Request::create(str_replace('sha=','sha=bad',$url));$reject(fn()=>$middleware->handle($bad,fn()=>'wrong','relative'),403);
$expired=URL::temporarySignedRoute('factory-agent.release',now()->subMinute(),['version'=>$version,'sha'=>str_repeat('a',64)],false);$reject(fn()=>$middleware->handle(Illuminate\Http\Request::create('https://portal.example.test'.$expired),fn()=>'wrong','relative'),403);
$ordinary=new App\Models\User(['role'=>'operator']);$r->setUserResolver(fn()=>$ordinary);$reject(fn()=>$monitor->updater($r),403);
$check(!str_contains($body,'Set-ExecutionPolicy') && !str_contains($body,'ExecutionPolicy Bypass'),'Updater does not change global execution policy or use bypass');
echo "$checks combined updater checks passed.\n";
