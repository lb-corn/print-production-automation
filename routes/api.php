<?php
// Monitoring deliberately does not acquire the production write lock or invoke batch actions.
\Illuminate\Support\Facades\Route::prefix('factory')->group(function(){
    \Illuminate\Support\Facades\Route::get('/batches/{batch}/completion',[\App\Http\Controllers\FactoryController::class,'completion'])->middleware(['throttle:factory-completion','factory.key']);
    \Illuminate\Support\Facades\Route::get('/work',[\App\Http\Controllers\FactoryController::class,'work'])->middleware(['throttle:factory-work','factory.key']);
    \Illuminate\Support\Facades\Route::get('/health',[\App\Http\Controllers\FactoryMonitoringController::class,'health'])->middleware(['throttle:factory-health','factory.key'])->name('factory.health');
    \Illuminate\Support\Facades\Route::post('/heartbeat',[\App\Http\Controllers\FactoryMonitoringController::class,'heartbeat'])->middleware(['throttle:factory-heartbeat','factory.key'])->name('factory.heartbeat');
});
use Illuminate\Support\Facades\Route;use App\Http\Controllers\FactoryController;Route::middleware(['factory.key','throttle:120,1', \App\Http\Middleware\PortalWriteLock::class])->prefix('factory')->group(function(){Route::get('/batches/next',[FactoryController::class,'next']);Route::post('/batches/{batch}/status',[FactoryController::class,'status']);});

\Illuminate\Support\Facades\Route::middleware(['factory.key','throttle:120,1',\App\Http\Middleware\PortalWriteLock::class])->prefix('factory')->group(function(){\Illuminate\Support\Facades\Route::post('/batches/{batch}/begin-work',[FactoryController::class,'beginWork']);\Illuminate\Support\Facades\Route::get('/batches/{batch}/assigned',[FactoryController::class,'assigned']);});

\Illuminate\Support\Facades\Route::get('/factory/sync-artwork',[\App\Http\Controllers\FactoryController::class,'syncArtwork'])->middleware(['factory.key','throttle:60,1']);

\Illuminate\Support\Facades\Route::middleware(['factory.key','throttle:120,1',\App\Http\Middleware\PortalWriteLock::class])->prefix('factory')->group(function(){
 \Illuminate\Support\Facades\Route::get('/custom-transfers',[\App\Http\Controllers\CustomArtworkController::class,'pending']);
 \Illuminate\Support\Facades\Route::post('/custom-transfers/{transfer}/begin',[\App\Http\Controllers\CustomArtworkController::class,'begin']);
 \Illuminate\Support\Facades\Route::post('/custom-transfers/{transfer}/report',[\App\Http\Controllers\CustomArtworkController::class,'report']);
 \Illuminate\Support\Facades\Route::get('/custom-transfers/{transfer}/completion',[\App\Http\Controllers\CustomArtworkController::class,'completion']);
});

// POST acquires the same write lock as work claims, while this action remains read-only.
\Illuminate\Support\Facades\Route::post('/factory/maintenance-check',[FactoryController::class,'maintenance'])->middleware(['factory.key','throttle:factory-work',\App\Http\Middleware\PortalWriteLock::class]);
