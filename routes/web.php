<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AipController;
use App\Http\Controllers\AipDocumentController;
use App\Http\Controllers\AipSummaryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OfficeBudgetController;
use App\Http\Controllers\PlanningDeletionController;
use App\Http\Controllers\PpmpController;
use App\Http\Controllers\PpmpReceivingController;
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
    Route::post('aips/documents/{document}/review', [AipSummaryController::class, 'decide'])->name('aips.documents.review');
    Route::put('aips/documents/{document}', [AipDocumentController::class, 'update'])->name('aips.documents.update');
    Route::get('aips/documents/{document}/summary', AipSummaryController::class)->name('aips.documents.summary');
    Route::get('aips/{ppa}/ppmp', [PpmpController::class, 'show'])->name('ppmp.show');
    Route::get('aips/{ppa}/ppmp/{sheet}/output/{format}', \App\Http\Controllers\PpmpOutputController::class)
        ->whereNumber('sheet')->whereIn('format', ['preview', 'xlsx'])->name('ppmp.output');
    Route::get('consolidated-ppmp', \App\Http\Controllers\ConsolidatedPpmpController::class)
        ->name('ppmp.consolidated');
    Route::get('consolidated-ppmp/excel', \App\Http\Controllers\ConsolidatedPpmpController::class)
        ->name('ppmp.consolidated.xlsx');
    Route::prefix('procurement')->name('procurement.')->group(function () {
        Route::get('app', [\App\Http\Controllers\AnnualProcurementController::class, 'index'])->name('app.index');
        Route::post('app/{year}/build', [\App\Http\Controllers\AnnualProcurementController::class, 'build'])
            ->whereNumber('year')->name('app.build');
        Route::post('app/{year}/decision', [\App\Http\Controllers\AnnualProcurementController::class, 'decide'])
            ->whereNumber('year')->name('app.decide');

        Route::get('requests', [\App\Http\Controllers\PurchaseRequestController::class, 'index'])->name('requests.index');
        Route::post('requests', [\App\Http\Controllers\PurchaseRequestController::class, 'store'])->name('requests.store');
        Route::patch('requests/{pr}', [\App\Http\Controllers\PurchaseRequestController::class, 'resubmit'])
            ->whereNumber('pr')->name('requests.resubmit');
        Route::post('requests/{pr}/decision', [\App\Http\Controllers\PurchaseRequestController::class, 'decide'])
            ->whereNumber('pr')->name('requests.decide');
    });

    Route::get('ppmp-reviews', [PpmpController::class, 'index'])->name('ppmp.reviews');
    Route::get('ppmp-approvals', [\App\Http\Controllers\PpmpApprovalController::class, 'index'])
        ->name('ppmp.approvals');
    Route::post('ppmp-approvals/{ppmp}', [\App\Http\Controllers\PpmpApprovalController::class, 'decide'])
        ->whereNumber('ppmp')->name('ppmp.approvals.decide');
    Route::post('aips/{ppa}/ppmp/{ppmp}/review', [PpmpController::class, 'review'])->name('ppmp.review');
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

    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/purchase-orders', [PpmpReceivingController::class, 'index'])->name('inventory.purchase-orders');
    Route::prefix('inventory/ppmp-items/{item}/quarters/{quarter}')
        ->where(['item' => '[0-9]+', 'quarter' => '[1-4]'])
        ->group(function () {
            Route::get('/', [PpmpReceivingController::class, 'show'])->name('inventory.ppmp-receiving.show');
            Route::post('orders', [PpmpReceivingController::class, 'storeOrder'])->name('inventory.ppmp-receiving.orders');
            Route::post('orders/{order}/receipts', [PpmpReceivingController::class, 'storeReceipt'])
                ->whereNumber('order')->name('inventory.ppmp-receiving.receipts');
        });
    Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::get('inventory/{asset}', [InventoryController::class, 'show'])
        ->whereNumber('asset')->name('inventory.show');
    Route::post('inventory/{asset}/movements', [InventoryController::class, 'move'])
        ->whereNumber('asset')->name('inventory.movements.store');

    Route::get('administration', [AdministrationController::class, 'index'])
        ->name('administration.index');

    Route::post('administration/offices', [AdministrationController::class, 'storeOffice'])
        ->name('administration.offices.store');
    Route::patch('administration/offices/{office}', [AdministrationController::class, 'updateOffice'])
        ->whereNumber('office')->name('administration.offices.update');

    Route::patch('administration/users/{user}', [AdministrationController::class, 'updateUser'])
        ->name('administration.user.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
