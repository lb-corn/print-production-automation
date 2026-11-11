<?php
namespace App\Http\Controllers;

use App\Services\ArtworkRenameStore;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->allows(\App\Services\RouteAccess::section($request->query('section','artwork')) ?? ''),403);
        $section=$request->query('section','artwork');
        abort_unless(in_array($section,['artwork','filenames','sync','roles','users','reset','agent'],true),404);
        if($section==='agent')return view('admin.settings',['section'=>'agent','agentReleases'=>app(\App\Services\FactoryAgentReleaseService::class)->releases(),'monitoring'=>app(\App\Services\FactoryMonitoringService::class)->snapshot(),'agentPackage'=>is_file(resource_path('factory-agent-release.json'))?json_decode(file_get_contents(resource_path('factory-agent-release.json')),true):[]]);
        if($section==='roles')return app(RoleController::class)->index();
        if($section==='users')return app(AdminController::class)->index();
        if($section==='filenames')return app(FilenameCleanupController::class)->index($request);
        if($section==='sync') {
            try{$plan=$request->query('plan')?app(ArtworkRenameStore::class)->load((string)$request->query('plan'),$request->user()->id,true):null;}
            catch(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e){return redirect()->route('admin.settings',['section'=>'sync','partner'=>\App\Services\PartnerContext::current()])->with('warning','This catalog preview is unavailable for the selected company. Sync the catalog again.');}
            $summary=$plan?ArtworkRenameStore::summary($plan):app(ArtworkRenameStore::class)->latestSummary($request->user()->id);
            return view('admin.settings',['section'=>'sync','plan'=>$plan,'summary'=>$summary]);
        }
        return $section==='reset' ? app(AdminController::class)->resetPage()
            : app(ArtworkNamingController::class)->index($request,app(ArtworkRenameStore::class));
    }
}
