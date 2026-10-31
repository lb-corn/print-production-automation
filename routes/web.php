<?php
use Illuminate\Support\Facades\Route;use App\Http\Controllers\AuthController;use App\Http\Controllers\ProductionController;
Route::get('/',fn()=>redirect('/dashboard'));
Route::middleware('guest')->group(function(){Route::get('/login',[AuthController::class,'show'])->name('login');Route::post('/login',[AuthController::class,'login']);});
Route::middleware(['auth', 'auth.session', \App\Http\Middleware\ReadOnlyDemo::class, \App\Http\Middleware\PortalWriteLock::class])->group(function(){Route::get('/workspace/status',[ProductionController::class,'workspaceStatus'])->middleware('throttle:30,1')->name('workspace.status');Route::get('/docs',fn()=>view('help.index'))->name('docs');Route::get('/profile',fn()=>view('auth.profile'))->name('profile');Route::post('/logout',[AuthController::class,'logout'])->name('logout');Route::get('/dashboard',[ProductionController::class,'dashboard'])->name('dashboard');Route::post('/production/sync-backlog',[ProductionController::class,'syncBacklog'])->name('production.sync-backlog');Route::get('/production',[ProductionController::class,'verification'])->name('production');Route::post('/production/bulk-verify',[ProductionController::class,'bulkVerify'])->name('production.bulk-verify');Route::post('/production/{item}/verify',[ProductionController::class,'verify'])->name('verify');Route::get('/ready-for-print',[ProductionController::class,'queue'])->name('queue');Route::post('/batches',[ProductionController::class,'batch'])->name('batch.create');Route::get('/batches',[ProductionController::class,'batches'])->name('batches');Route::get('/batches/{batch}',[ProductionController::class,'showBatch'])->name('batch.show');Route::post('/batches/{batch}/edge-print',[ProductionController::class,'requestEdgePrint'])->name('batch.edge-print');Route::post('/batches/{batch}/printed',[ProductionController::class,'markPrinted'])->name('batch.printed');});

Route::middleware(['auth', 'auth.session', \App\Http\Middleware\RequireAdmin::class, \App\Http\Middleware\ReadOnlyDemo::class, \App\Http\Middleware\PortalWriteLock::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [\App\Http\Controllers\AdminController::class, 'index'])->name('users');
    Route::get('/users/create', [\App\Http\Controllers\AdminController::class, 'create'])->name('users.create');
    Route::post('/users', [\App\Http\Controllers\AdminController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [\App\Http\Controllers\AdminController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [\App\Http\Controllers\AdminController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/status', [\App\Http\Controllers\AdminController::class, 'status'])->name('users.status');
    Route::get('/users/{user}/delete', [\App\Http\Controllers\AdminController::class, 'deletePage'])->name('users.delete');
    Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'destroy'])->name('users.destroy');
    Route::get('/reset', [\App\Http\Controllers\AdminController::class, 'resetPage'])->name('reset');
    Route::post('/reset', [\App\Http\Controllers\AdminController::class, 'reset'])->middleware('throttle:3,1')->name('reset.store');
});