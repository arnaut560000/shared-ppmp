<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AipController;
use App\Http\Controllers\AipDocumentController;
use App\Http\Controllers\AipSummaryController;
use App\Http\Controllers\OfficeBudgetController;
use App\Http\Controllers\PlanningDeletionController;
use App\Http\Controllers\PpmpController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::prefix('aips/deletion/{kind}/{id}')->where(['kind' => 'aip|ppa|sub-ppa', 'id' => '[0-9]+'])->group(function () {
        Route::get('/', [PlanningDeletionController::class, 'preview'])->name('aips.deletion.preview');
        Route::delete('/', [PlanningDeletionController::class, 'destroy'])->name('aips.deletion.destroy');
    });
    Route::put('aips/documents/{document}', [AipDocumentController::class, 'update'])->name('aips.documents.update');
    Route::get('aips/documents/{document}/summary', AipSummaryController::class)->name('aips.documents.summary');
    Route::get('aips/{ppa}/ppmp', [PpmpController::class, 'show'])->name('ppmp.show');
    Route::get('my-ppmps', [PpmpController::class, 'index'])->name('ppmp.index');
    Route::post('aips/{ppa}/ppmp', [PpmpController::class, 'store'])->name('ppmp.store');
    Route::put('aips/{ppa}/ppmp/{ppmp}', [PpmpController::class, 'update'])->name('ppmp.update');
    Route::get('budgets', [OfficeBudgetController::class, 'index'])->name('budgets.index');
    Route::post('budgets', [OfficeBudgetController::class, 'store'])->name('budgets.store');
    Route::get('aips/{plan}/ppas/create', [AipController::class, 'createPpa'])->name('aips.ppas.create');
    Route::get('aips', [AipDocumentController::class, 'index'])
        ->name('aips.index');

    Route::get('aips/offices/{office}', [AipController::class, 'workspace'])->name('aips.offices.workspace');

    Route::post('aips/documents', [AipDocumentController::class, 'store'])->name('aips.documents.store');
    Route::get('aips/documents/{document}', [AipController::class, 'show'])->name('aips.documents.show');
    Route::get('aips/{plan}/sub-ppas/create', [AipController::class, 'createPpa'])->name('aips.sub-ppas.create');
    Route::get('aips/{plan}/view', [AipController::class, 'view'])->name('aips.view');

    Route::post('aips', [AipController::class, 'store'])
        ->name('aips.store');

    Route::get('aips/{plan}/edit', [AipController::class, 'edit'])
        ->name('aips.edit');

    Route::put('aips/{plan}', [AipController::class, 'update'])
        ->name('aips.update');

    Route::get('administration', [AdministrationController::class, 'index'])
        ->name('administration.index');

    Route::post('administration/offices', [AdministrationController::class, 'storeOffice'])
        ->name('administration.offices.store');

    Route::patch('administration/users/{user}', [AdministrationController::class, 'updateUser'])
        ->name('administration.user.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
