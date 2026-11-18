<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UpdatePlanController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\VulnerabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/', [AuthController::class, 'create']);
Route::middleware('auth')->group(function () {
    Route::resource('assets', AssetController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::get('/vulnerabilities', [VulnerabilityController::class, 'index'])->name('vulnerabilities.index');
    Route::get('/vulnerabilities/create', [VulnerabilityController::class, 'create'])->name('vulnerabilities.create');
    Route::post('/vulnerabilities', [VulnerabilityController::class, 'store'])->name('vulnerabilities.store');
    Route::get('/vulnerabilities/{vulnerability}', [VulnerabilityController::class, 'show'])->name('vulnerabilities.show');
    Route::post('/vulnerabilities/{vulnerability}/ranges', [VulnerabilityController::class, 'range'])->name('vulnerabilities.ranges.store');
    Route::delete('/vulnerabilities/{vulnerability}/ranges/{range}', [VulnerabilityController::class, 'range'])->name('vulnerabilities.ranges.destroy');
    Route::post('/vulnerabilities/{vulnerability}/assessments', [VulnerabilityController::class, 'assess'])->name('vulnerabilities.assessments.store');
    Route::post('/vulnerability-assessments/{assessment}/transition', [VulnerabilityController::class, 'transition'])->name('vulnerabilities.assessments.transition');
    Route::post('/vulnerability-assessments/{assessment}/update-plan', [VulnerabilityController::class, 'createUpdatePlan'])->name('vulnerabilities.assessments.update-plan');
    Route::post('/assets/{asset}/components', [AssetController::class, 'component'])->name('assets.components.store');
    Route::post('/assets/{asset}/lifecycle', [AssetController::class, 'lifecycle'])->name('assets.lifecycle.store');
    Route::post('/assets/{asset}/relationships', [AssetController::class, 'relationship'])->name('assets.relationships.store');
    Route::delete('/assets/{asset}/relationships/{relationship}', [AssetController::class, 'destroyRelationship'])->name('assets.relationships.destroy');
    Route::put('/assets/{asset}/components/{component}', [AssetController::class, 'updateComponent'])->name('assets.components.update');
    Route::get('/update-plans', [UpdatePlanController::class, 'index'])->name('update-plans.index');
    Route::get('/update-plans/create', [UpdatePlanController::class, 'create'])->name('update-plans.create');
    Route::post('/update-plans', [UpdatePlanController::class, 'store'])->name('update-plans.store');
    Route::get('/update-plans/{updatePlan}', [UpdatePlanController::class, 'show'])->name('update-plans.show');
    Route::put('/update-plans/{updatePlan}', [UpdatePlanController::class, 'update'])->name('update-plans.update');
    Route::post('/update-plans/{updatePlan}/transition', [UpdatePlanController::class, 'transition'])->name('update-plans.transition');
    Route::post('/update-plans/{updatePlan}/checklist', [UpdatePlanController::class, 'checklist'])->name('update-plans.checklist');
    Route::post('/checklist-items/{item}/status', [UpdatePlanController::class, 'checklistStatus'])->name('checklist-items.status');
    Route::post('/update-plans/{updatePlan}/approvals', [UpdatePlanController::class, 'approve'])->name('update-plans.approve');
    Route::middleware('can:manage-users')->prefix('settings/users')->name('settings.users.')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        Route::put('/{user}/roles', [UserManagementController::class, 'updateRoles'])->name('roles.update');
    });
});
