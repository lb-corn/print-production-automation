<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider {
 public function register():void {$this->app->scoped(\App\Services\ProductionService::class);}
 public function boot():void {
  \Illuminate\Support\Facades\View::composer('partials.workspace-status',function($view){
   $view->with('workspaceStats',app(\App\Services\ProductionService::class)->workspaceStats());
  });
 }
}
