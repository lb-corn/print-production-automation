<?php
// Monitoring deliberately does not acquire the production write lock or invoke batch actions.
\Illuminate\Support\Facades\Route::prefix('factory')->group(function(){
    \Illuminate\Support\Facades\Route::get('/health',[\App\Http\Controllers\FactoryMonitoringController::class,'health'])->middleware(['throttle:factory-health','factory.key'])->name('factory.health');
    \Illuminate\Support\Facades\Route::post('/heartbeat',[\App\Http\Controllers\FactoryMonitoringController::class,'heartbeat'])->middleware(['throttle:factory-heartbeat','factory.key'])->name('factory.heartbeat');
});
use Illuminate\Support\Facades\Route;use App\Http\Controllers\FactoryController;Route::middleware(['factory.key','throttle:120,1', \App\Http\Middleware\PortalWriteLock::class])->prefix('factory')->group(function(){Route::get('/batches/next',[FactoryController::class,'next']);Route::post('/batches/{batch}/status',[FactoryController::class,'status']);});
