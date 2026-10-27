<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BudgetController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)
    ->name('dashboard');

    Route::get('budgets', [BudgetController::class, 'index'])
        ->name('budgets.index');

    Route::post('budgets', [BudgetController::class, 'store'])
        ->name('budgets.store');

    Route::get('administrator', [AdministrationController::class, 'index'])
    ->name('administration.index');

    Route::post('administration/offices', [AdministrationController::class, 'storeOffice'])
    ->name('administration.offices.store');

    Route::patch('administration/users/{user}', [AdministrationController::class,'updateUser'])
    ->name('administration.user.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
