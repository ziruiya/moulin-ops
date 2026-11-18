<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/', [AuthController::class, 'create']);
Route::middleware('auth')->group(function () {
    Route::resource('assets', AssetController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::post('/assets/{asset}/components', [AssetController::class, 'component'])->name('assets.components.store');
    Route::post('/assets/{asset}/lifecycle', [AssetController::class, 'lifecycle'])->name('assets.lifecycle.store');
    Route::post('/assets/{asset}/relationships', [AssetController::class, 'relationship'])->name('assets.relationships.store');
    Route::delete('/assets/{asset}/relationships/{relationship}', [AssetController::class, 'destroyRelationship'])->name('assets.relationships.destroy');
});
