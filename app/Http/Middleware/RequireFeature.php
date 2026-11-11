<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Services\RouteAccess;
class RequireFeature {
    public function handle(Request $request, Closure $next) {
        $name=$request->route()?->getName() ?? '';
        if(in_array($name,['logout','profile','docs'],true)) return $next($request);
        if($name==='orders.sync') {
            abort_unless($request->user()->allows('bags.sync.edit') || $request->user()->allows('apparel.sync.edit'),403);
            return $next($request);
        }
        if(in_array($name,['workspace.status','orders.sync-status'],true)) {
            abort_unless($request->user()->allows('bags.overview.view') || $request->user()->allows('apparel.overview.view'),403,'Your role does not allow production summaries.');
            return $next($request);
        }
        if($name==='dashboard') {
            if(!$request->user()->allows('bags.overview.view') && !$request->user()->allows('apparel.overview.view') && !$request->has('area')) return response()->view('dashboard.access');
            $area=$request->query('area',$request->user()->allows('bags.overview.view')?'bags':'apparel');
            abort_unless(in_array($area,['bags','apparel'],true) && $request->user()->allows($area.'.overview.view'),403);
            if($area==='apparel') return response(app(\App\Http\Controllers\ApparelOverviewController::class)->index());
            return $next($request);
        }
        if($name==='admin.settings' && $request->query('section')==='reset')abort_unless(count(\App\Services\PartnerContext::allowed($request->user()))===count(config('services.partners',[])),403,'Reset requires access to every production partner.');
        $permission=$name==='admin.settings'?RouteAccess::section($request->query('section','artwork')):RouteAccess::permission($name);
        abort_unless($permission && $request->user()?->allows($permission),403,'Your role does not allow this feature.');
        return $next($request);
    }
}
