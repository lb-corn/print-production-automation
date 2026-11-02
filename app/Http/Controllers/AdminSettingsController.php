<?php
namespace App\Http\Controllers;

use App\Services\ArtworkRenameStore;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->role==='admin',403);
        $section=$request->query('section','artwork');
        abort_unless(in_array($section,['artwork','filenames','sync','reset'],true),404);
        if($section==='filenames')return app(FilenameCleanupController::class)->index($request);
        if($section==='sync') {
            $plan=$request->query('plan')?app(ArtworkRenameStore::class)->load((string)$request->query('plan'),$request->user()->id,true):null;
            $summary=$plan?ArtworkRenameStore::summary($plan):app(ArtworkRenameStore::class)->latestSummary($request->user()->id);
            return view('admin.settings',['section'=>'sync','plan'=>$plan,'summary'=>$summary]);
        }
        return $section==='reset' ? app(AdminController::class)->resetPage()
            : app(ArtworkNamingController::class)->index($request,app(ArtworkRenameStore::class));
    }
}
