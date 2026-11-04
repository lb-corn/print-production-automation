<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider {
 public function register():void {$this->app->scoped(\App\Services\MaterialCatalog::class);$this->app->scoped(\App\Services\ProductionService::class);$this->app->scoped(\App\Services\FactoryDispatchService::class);}
 public function boot():void {
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
   $view->with('workspaceStats',app(\App\Services\ProductionService::class)->workspaceStats());
  });
 }
}
