<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider {
 public function register():void {$this->app->scoped(\App\Services\PartnerContext::class);$this->app->scoped(\App\Services\ProductionSummaryService::class);$this->app->scoped(\App\Services\MaterialCatalog::class);$this->app->scoped(\App\Services\ProductionService::class);$this->app->scoped(\App\Services\FactoryDispatchService::class);}
 public function boot():void {
  RateLimiter::for('order-sync',fn(Request $request)=>Limit::perMinute(3)->by('order-sync:'.($request->user()?->id??$request->ip()))->response(function(Request $request,array $headers){
   $message='Please wait '.max(1,(int)($headers['Retry-After']??60)).' seconds before syncing orders again.';
   if($request->expectsJson())return response()->json(['message'=>$message],429,$headers);
   return redirect()->route('dashboard',['area'=>$request->input('area')==='apparel'?'apparel':'bags','partner'=>\App\Services\PartnerContext::current()])->with('warning',$message)->withHeaders($headers);
  }));
  RateLimiter::for('order-sync-status',fn(Request $request)=>Limit::perMinute(60)->by('order-sync-status:'.($request->user()?->id??$request->ip())));
  RateLimiter::for('backing-suggestions',fn(Request $request)=>Limit::perMinute(120)->by('backing-suggestions:'.($request->user()?->id??$request->ip())));
  RateLimiter::for('backing-references',fn(Request $request)=>Limit::perMinute(180)->by('backing-references:'.($request->user()?->id??$request->ip())));
  $this->loadViewsFrom(base_path('packages/ui-theme/resources/views'),'lucky-theme');
  $this->loadViewsFrom(base_path('packages/ui-theme/resources/views'),'production-ui');
  \Illuminate\Support\Facades\Blade::anonymousComponentPath(base_path('packages/ui-theme/resources/views/components'),'production-ui');
  RateLimiter::for('admin-reset',fn(Request $request)=>Limit::perMinute(3)->by('admin-reset:'.($request->user()?->id??$request->ip()))->response(function(Request $request,array $headers){$message='Please wait '.max(1,(int)($headers['Retry-After']??60)).' seconds before another reset attempt. No reset was performed.';return $request->expectsJson()?response()->json(['message'=>$message],429,$headers):redirect()->route('admin.settings',['section'=>'reset'])->with('error',$message)->withHeaders($headers);}));
  RateLimiter::for('factory-health',fn(Request $request)=>Limit::perMinute(30)->by('factory-health:'.$request->ip()));
  RateLimiter::for('factory-completion',fn(Request $request)=>Limit::perMinute(60)->by('factory-completion:'.$request->ip()));
  RateLimiter::for('factory-work',fn(Request $request)=>Limit::perMinute(60)->by('factory-work:'.$request->ip()));
  RateLimiter::for('factory-progress',fn(Request $request)=>Limit::perMinute(60)->by('factory-progress:'.($request->user()?->id??$request->ip())));
  RateLimiter::for('factory-status',fn(Request $request)=>Limit::perMinute(60)->by('factory-status:'.($request->user()?->id??$request->ip())));
  RateLimiter::for('factory-connection',fn(Request $request)=>Limit::perMinute(60)->by('factory-connection:'.($request->user()?->id??$request->ip())));
  RateLimiter::for('factory-heartbeat',fn(Request $request)=>Limit::perMinute(60)->by('factory-heartbeat:'.$request->ip()));
  RateLimiter::for('filename-cleanup',function(Request $request){
   return Limit::perMinute(10)->by('filename-cleanup:'.($request->user()?->getAuthIdentifier()??$request->ip()))
    ->response(function(Request $request,array $headers){
     $message='Please wait '.max(1,(int)($headers['Retry-After']??60)).' seconds before processing another filename. This request did not rename any file.';
     if($request->expectsJson())return response()->json(['message'=>$message],429,$headers);
     return redirect()->route('admin.settings',['section'=>'filenames'])->with('error',$message)->withHeaders($headers);
    });
  });
  RateLimiter::for('catalog-sync', function (Request $request) {
   return Limit::perMinute(3)
    ->by('catalog-sync:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
    ->response(function (Request $request, array $headers) {
     $seconds=max(1,(int)($headers['Retry-After'] ?? 60));
     $message='Catalog sync limit reached. Please wait '.$seconds.' seconds and try again. This request did not change the catalog, orders or print queues.';
     if ($request->expectsJson()) return response()->json(['message'=>$message],429,$headers);
     return redirect()->route('admin.settings',['section'=>'sync'])->with('error',$message)->withHeaders($headers);
    });
  });
  RateLimiter::for('artwork-rematch', function (Request $request) {
   return Limit::perMinute(3)
    ->by('artwork-rematch:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
    ->response(function (Request $request, array $headers) {
     $seconds=max(1,(int)($headers['Retry-After'] ?? 60));
     $message='Artwork rematch limit reached. Please wait '.$seconds.' seconds and try again. No artwork or batches were changed by this request.';
     if ($request->expectsJson()) return response()->json(['message'=>$message],429,$headers);
     return redirect()->route('production')->with('error',$message)->withHeaders($headers);
    });
  });
  \Illuminate\Support\Facades\View::composer('partials.workspace-status',function($view){
   $view->with('productionSummary',auth()->user() ? app(\App\Services\ProductionSummaryService::class)->forUser(auth()->user()) : ['areas'=>[]]);
  });
 }
}
