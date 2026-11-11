<?php
namespace App\Http\Middleware;
use App\Services\PartnerContext;
use Illuminate\Http\Request;
use Closure;

class PartnerScope
{
    public function handle(Request $request,Closure $next) {
        if(!$request->user())return $next($request);
        $allowed=PartnerContext::allowed($request->user());
        $requested=$request->header('X-Production-Partner')??$request->input('partner');
        $partner=$requested??$request->session()->get('production_partner');
        if($requested!==null)abort_unless(is_string($partner) && in_array($partner,$allowed,true),403,'Your role does not allow this partner.');
        if(!in_array($partner,$allowed,true))$partner=$allowed[0]??'lucky';
        $name=$request->route()?->getName()??'';
        $global=in_array($name,['login','logout','profile','docs','workspace.factory-status'],true)
            || str_starts_with($name,'admin.users') || str_starts_with($name,'admin.roles') || str_starts_with($name,'admin.factory-agent.')
            || ($name==='admin.settings' && in_array($request->query('section'),['users','roles','agent'],true))
            || ($name==='dashboard' && !$request->user()->allows('bags.overview.view') && !$request->user()->allows('apparel.overview.view'));
        if(!$allowed && !$global)abort(403,'No production partner is assigned to your role.');
        $ctx=PartnerContext::get();$previous=[$ctx->partner,$ctx->scoped,$ctx->denied];
        $ctx->partner=$partner;$ctx->scoped=true;$ctx->denied=!$allowed;
        try {
        $request->session()->put('production_partner',$partner);
        foreach($request->route()?->parameters??[] as $value)if($value instanceof \Illuminate\Database\Eloquent\Model && $value->getAttribute('partner')!==null)abort_unless($value->partner===$partner,404);
        return $next($request);
        }finally{[$ctx->partner,$ctx->scoped,$ctx->denied]=$previous;}
    }
}
