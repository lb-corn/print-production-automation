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
        $name=$request->route()?->getName()??'';
        if($requested===null && str_starts_with($name,'admin.')){
            $remembered=$request->session()->get('production_admin_partner');
            if(in_array($remembered,$allowed,true))$requested=$remembered;
        }
        $partner=$requested??(count($allowed)>1?'all':($allowed[0]??'lucky'));
        if($requested!==null)abort_unless(is_string($partner) && ($partner==='all' || in_array($partner,$allowed,true)),403,'Your role does not allow this partner.');
        if($partner==='all' && (str_starts_with($name,'admin.') || $name==='apparel.orders.sync' || $name==='production.sync-backlog')){
            $remembered=str_starts_with($name,'admin.')?$request->session()->get('production_admin_partner'):null;
            $partner=in_array($remembered,$allowed,true)?$remembered:($allowed[0]??'lucky');
        }
        if($partner==='all')foreach(['item'=>['production_items','id'],'batch'=>['print_batches','id'],'jerseyOrder'=>['jersey_orders','id']] as $parameter=>[$table,$key]){
            $id=$request->route()?->parameters[$parameter]??null;
            if($id!==null){$owner=$id instanceof \Illuminate\Database\Eloquent\Model?$id->getAttribute('partner'):\Illuminate\Support\Facades\DB::table($table)->where($key,$id)->value('partner');abort_unless(in_array($owner,$allowed,true),404);$partner=$owner;break;}
        }
        $global=in_array($name,['login','logout','profile','docs','workspace.factory-status'],true)
            || str_starts_with($name,'admin.users') || str_starts_with($name,'admin.roles') || str_starts_with($name,'admin.factory-agent.')
            || ($name==='admin.settings' && in_array($request->query('section'),['users','roles','agent'],true))
            || ($name==='dashboard' && !$request->user()->allows('bags.overview.view') && !$request->user()->allows('apparel.overview.view'));
        if(!$allowed && !$global)abort(403,'No production partner is assigned to your role.');
        $ctx=PartnerContext::get();$previous=[$ctx->partner,$ctx->scoped,$ctx->denied,$ctx->permitted];
        $ctx->partner=$partner;$ctx->scoped=true;$ctx->denied=!$allowed;$ctx->permitted=$allowed;
        try {
        if(str_starts_with($name,'admin.') && in_array($partner,$allowed,true))$request->session()->put('production_admin_partner',$partner);
        foreach($request->route()?->parameters??[] as $value)if($value instanceof \Illuminate\Database\Eloquent\Model && $value->getAttribute('partner')!==null)abort_unless($value->partner===$partner,404);
        return $next($request);
        }finally{[$ctx->partner,$ctx->scoped,$ctx->denied,$ctx->permitted]=$previous;}
    }
}
