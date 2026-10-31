<?php
use Illuminate\Support\Facades\Route;use App\Http\Controllers\FactoryController;Route::middleware(['factory.key','throttle:120,1', \App\Http\Middleware\PortalWriteLock::class])->prefix('factory')->group(function(){Route::get('/batches/next',[FactoryController::class,'next']);Route::post('/batches/{batch}/status',[FactoryController::class,'status']);});
