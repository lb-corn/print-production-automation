<?php
use Illuminate\Support\Facades\Route;use App\Http\Controllers\AuthController;use App\Http\Controllers\ProductionController;
Route::get('/',fn()=>redirect('/dashboard'));
Route::middleware('guest')->group(function(){Route::get('/login',[AuthController::class,'show'])->name('login');Route::post('/login',[AuthController::class,'login']);});
Route::middleware(['auth', 'auth.session', \App\Http\Middleware\RequireFeature::class, \App\Http\Middleware\ReadOnlyDemo::class, \App\Http\Middleware\PortalWriteLock::class])->group(function(){Route::get('/workspace/factory-status',[\App\Http\Controllers\FactoryMonitoringController::class,'connection'])->middleware('throttle:factory-connection')->name('workspace.factory-status');Route::get('/workspace/status',[ProductionController::class,'workspaceStatus'])->middleware('throttle:30,1')->name('workspace.status');Route::get('/docs',fn()=>view('help.index'))->name('docs');Route::get('/profile',fn()=>view('auth.profile'))->name('profile');Route::post('/logout',[AuthController::class,'logout'])->name('logout');Route::get('/dashboard',[ProductionController::class,'dashboard'])->name('dashboard');Route::get('/orders/sync-status',fn()=>view('partials.order-sync-status'))->middleware('throttle:order-sync-status')->name('orders.sync-status');Route::post('/orders/sync',[\App\Http\Controllers\OrderSyncController::class,'store'])->middleware('throttle:order-sync')->name('orders.sync');Route::post('/production/sync-backlog',[ProductionController::class,'syncBacklog'])->name('production.sync-backlog');Route::get('/production',[ProductionController::class,'verification'])->name('production');Route::post('/production/rematch',[ProductionController::class,'rematchArtwork'])->middleware('throttle:artwork-rematch')->name('production.rematch');Route::post('/production/bulk-verify',[ProductionController::class,'bulkVerify'])->name('production.bulk-verify');Route::post('/production/{item}/verify',[ProductionController::class,'verify'])->name('verify');Route::get('/ready-for-print',[ProductionController::class,'queue'])->name('queue');Route::post('/batches',[ProductionController::class,'batch'])->name('batch.create');Route::get('/batches',[ProductionController::class,'batches'])->name('batches');Route::get('/batches/{batch}/progress',[ProductionController::class,'batchProgress'])->middleware('throttle:factory-progress')->name('batch.progress');Route::post('/batches/{batch}/dispatch',[ProductionController::class,'requestPreparation'])->name('batch.dispatch');Route::get('/batches/{batch}',[ProductionController::class,'showBatch'])->name('batch.show');Route::post('/batches/{batch}/items/{item}/return-to-review',[ProductionController::class,'returnItemToReview'])->name('batch.return-item');Route::post('/batches/{batch}/return-to-review',[ProductionController::class,'returnBatchToReview'])->name('batch.return-to-review');Route::post('/batches/{batch}/edge-print',[ProductionController::class,'requestEdgePrint'])->name('batch.edge-print');Route::post('/batches/{batch}/printed',[ProductionController::class,'markPrinted'])->name('batch.printed');});

Route::middleware(['auth', 'auth.session', \App\Http\Middleware\RequireFeature::class, \App\Http\Middleware\ReadOnlyDemo::class, \App\Http\Middleware\PortalWriteLock::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index'])->name('roles');
    Route::post('/roles', [\App\Http\Controllers\RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'update'])->name('roles.update');
    Route::get('/settings', [\App\Http\Controllers\AdminSettingsController::class, 'index'])->name('settings');
    Route::post('/filename-cleanup/process', [\App\Http\Controllers\FilenameCleanupController::class, 'process'])->middleware('throttle:filename-cleanup')->name('filename-cleanup.process');
    Route::post('/data-sync', [\App\Http\Controllers\ArtworkNamingController::class, 'syncSources'])->middleware('throttle:catalog-sync')->name('data-sync');
    Route::get('/artwork-names', fn(\Illuminate\Http\Request $request)=>redirect()->route('admin.settings',array_filter(['section'=>'artwork','plan'=>$request->query('plan'),'partner'=>\App\Services\PartnerContext::current()])))->name('artwork-names');
    Route::post('/artwork-names/scan', [\App\Http\Controllers\ArtworkNamingController::class, 'scan'])->name('artwork-names.scan');
    Route::post('/artwork-names/standardize', [\App\Http\Controllers\ArtworkNamingController::class, 'standardize'])->middleware('throttle:catalog-sync')->name('artwork-names.standardize');
    Route::post('/artwork-names/review', [\App\Http\Controllers\ArtworkNamingController::class, 'revise'])->name('artwork-names.review');
    Route::post('/artwork-names/begin', [\App\Http\Controllers\ArtworkNamingController::class, 'begin'])->name('artwork-names.begin');
    Route::post('/artwork-names/step', [\App\Http\Controllers\ArtworkNamingController::class, 'step'])->name('artwork-names.step');
    Route::get('/users', [\App\Http\Controllers\AdminController::class, 'index'])->name('users');
    Route::get('/users/create', [\App\Http\Controllers\AdminController::class, 'create'])->name('users.create');
    Route::post('/users', [\App\Http\Controllers\AdminController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [\App\Http\Controllers\AdminController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [\App\Http\Controllers\AdminController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/status', [\App\Http\Controllers\AdminController::class, 'status'])->name('users.status');
    Route::get('/users/{user}/delete', [\App\Http\Controllers\AdminController::class, 'deletePage'])->name('users.delete');
    Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'destroy'])->name('users.destroy');
    Route::post('/factory-agent/{agent:agent_id}/handoff-control',[\App\Http\Controllers\FactoryMonitoringController::class,'handoffControl'])->middleware('throttle:10,1')->name('factory-agent.handoff-control');
    Route::post('/factory-agent/{agent:agent_id}/processing',[\App\Http\Controllers\FactoryMonitoringController::class,'processing'])->middleware('throttle:10,1')->name('factory-agent.processing');
    Route::get('/factory-agent/updater',[\App\Http\Controllers\FactoryMonitoringController::class,'updater'])->middleware('throttle:10,1')->name('factory-agent.updater');
    Route::get('/factory-agent/package',[\App\Http\Controllers\FactoryMonitoringController::class,'portalPackage'])->middleware('throttle:10,1')->name('factory-agent.package');
    Route::get('/factory-agent/repair-startup',[\App\Http\Controllers\FactoryMonitoringController::class,'repairStartup'])->middleware('throttle:10,1')->name('factory-agent.repair-startup');
    Route::get('/factory-agent/setup',[\App\Http\Controllers\FactoryMonitoringController::class,'portalSetup'])->middleware('throttle:10,1')->name('factory-agent.setup');
    Route::get('/factory-agent/status',[\App\Http\Controllers\FactoryMonitoringController::class,'status'])->middleware('throttle:factory-status')->name('factory-agent.status');
    Route::get('/factory-agent/connection-reporter',[\App\Http\Controllers\FactoryMonitoringController::class,'connectionReporter'])->middleware('throttle:10,1')->name('factory-agent.connection-reporter');
    Route::get('/factory-agent/connectivity-script',[\App\Http\Controllers\FactoryMonitoringController::class,'connectivityScript'])->middleware('throttle:10,1')->name('factory-agent.connectivity-script');
    Route::get('/reset', fn()=>redirect()->route('admin.settings',['section'=>'reset']))->name('reset');
    Route::get('/reset/pc-cleanup', [\App\Http\Controllers\AdminController::class, 'pcCleanup'])->name('reset.pc-cleanup');
    Route::post('/reset', [\App\Http\Controllers\AdminController::class, 'reset'])->middleware('throttle:admin-reset')->name('reset.store');
});

Route::middleware(['auth','auth.session', \App\Http\Middleware\RequireFeature::class,\App\Http\Middleware\ReadOnlyDemo::class,\App\Http\Middleware\PortalWriteLock::class])->group(function(){
 Route::post('/production/{item}/custom-transfers',[\App\Http\Controllers\CustomArtworkController::class,'create'])->middleware('throttle:12,1')->name('custom.create');
 Route::get('/custom-transfers/{transfer}',[\App\Http\Controllers\CustomArtworkController::class,'status'])->middleware('throttle:120,1')->name('custom.status');
 Route::post('/custom-transfers/{transfer}/cancel',[\App\Http\Controllers\CustomArtworkController::class,'cancel'])->name('custom.cancel');
 Route::post('/production/{item}/custom-confirm',[\App\Http\Controllers\CustomArtworkController::class,'confirm'])->name('custom.confirm');
});

Route::get('/factory-agent/releases/{version}',[\App\Http\Controllers\FactoryMonitoringController::class,'signedPackage'])->middleware(['signed:relative','throttle:20,1'])->where('version','[0-9]+\\.[0-9]+\\.[0-9]+')->name('factory-agent.release');

Route::middleware(['auth','auth.session',\App\Http\Middleware\RequireFeature::class,\App\Http\Middleware\ReadOnlyDemo::class,\App\Http\Middleware\PortalWriteLock::class])->prefix('apparel')->name('apparel.')->group(function(){
    Route::get('/orders',[\App\Http\Controllers\JerseyOrderController::class,'index'])->name('orders.index');
    Route::get('/orders/{jerseyOrder}',[\App\Http\Controllers\JerseyOrderController::class,'show'])->name('orders.show');
    Route::patch('/orders/{jerseyOrder}/tracking',[\App\Http\Controllers\JerseyOrderTrackingController::class,'update'])->name('orders.tracking.update');
    Route::patch('/orders/{jerseyOrder}/remark',[\App\Http\Controllers\JerseyOrderRemarkController::class,'update'])->name('orders.remark.update');
    Route::patch('/orders/{jerseyOrder}/remark/processed',[\App\Http\Controllers\JerseyOrderRemarkProcessedController::class,'update'])->name('orders.remark.processed.update');
    Route::post('/orders/sync',[\App\Http\Controllers\ShopifySyncController::class,'store'])->middleware('throttle:order-sync')->name('orders.sync');
});

Route::middleware(['auth','auth.session',\App\Http\Middleware\RequireFeature::class,\App\Http\Middleware\ReadOnlyDemo::class,\App\Http\Middleware\PortalWriteLock::class])->group(function(){
    Route::get('/artwork-preview/{file}',[\App\Http\Controllers\ArtworkPreviewController::class,'show'])->middleware(['signed','throttle:240,1'])->name('artwork.preview');
    Route::post('/production/{item}/return-review',[\App\Http\Controllers\ProductionController::class,'returnReadyToReview'])->name('production.return-review');
    Route::get('/production/backing-reference',[\App\Http\Controllers\BackingColorController::class,'reference'])->middleware('throttle:backing-references')->name('backing.reference');
    Route::get('/production/{item}/backing-suggestion',[\App\Http\Controllers\BackingColorController::class,'suggest'])->middleware('throttle:backing-suggestions')->name('backing.suggest');
    Route::get('/production/{item}/backing-swatch',[\App\Http\Controllers\BackingColorController::class,'swatch'])->middleware('throttle:120,1')->name('backing.swatch');
});
