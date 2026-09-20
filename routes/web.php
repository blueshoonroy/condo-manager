<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Middleware\ActiveResident;
use App\Http\Middleware\Administrator;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'requestCode'])->middleware('throttle:5,1')->name('login.send');
    Route::view('/login/verify', 'auth.verify')->name('login.verify');
    Route::post('/login/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1')->name('login.check');
});
Route::middleware(['auth', ActiveResident::class])->group(function () {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/invoices', [PortalController::class, 'invoices'])->name('invoices');
    Route::get('/invoices/{invoice}', [PortalController::class, 'invoice'])->whereNumber('invoice')->name('invoice');
    Route::get('/finances', [PortalController::class, 'finances'])->name('finances');
    Route::get('/directory', [PortalController::class, 'directory'])->name('directory');
    Route::view('/documents', 'documents')->name('documents');
    Route::middleware(Administrator::class)->prefix('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin');
        Route::post('/imports', [AdminController::class, 'preview'])->name('admin.preview');
        Route::get('/imports/{batch}', [AdminController::class, 'import'])->whereNumber('batch')->name('admin.import');
        Route::post('/imports/{batch}', [AdminController::class, 'commit'])->whereNumber('batch')->name('admin.commit');
        Route::post('/balance', [AdminController::class, 'checkpoint'])->name('admin.balance');
        Route::get('/payments/new/{household}', [AdminController::class, 'paymentForm'])->whereNumber('household')->name('admin.payment');
        Route::post('/payments', [AdminController::class, 'payment'])->name('admin.payment.store');
        Route::get('/payments/{payment}/allocate', [AdminController::class, 'allocationForm'])->whereNumber('payment')->name('admin.allocations');
        Route::post('/payments/{payment}/allocate', [AdminController::class, 'allocate'])->whereNumber('payment')->name('admin.allocate');
        Route::post('/payments/{payment}/reverse', [AdminController::class, 'reverse'])->whereNumber('payment')->name('admin.reverse');
        Route::post('/assessments', [AdminController::class, 'assessment'])->name('admin.assessment');
        Route::post('/assessments/preview', [AdminController::class, 'assessmentPreview'])->name('admin.assessment.preview');
        Route::post('/assessments/confirm', [AdminController::class, 'assessmentConfirm'])->name('admin.assessment.confirm');
        Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation');
        Route::post('/reconciliation/settings', [ReconciliationController::class, 'settings'])->name('admin.ai.settings');
        Route::post('/reconciliation', [ReconciliationController::class, 'start'])->middleware('throttle:3,10')->name('admin.reconciliation.start');
        Route::get('/reconciliation/{run}', [ReconciliationController::class, 'show'])->whereNumber('run')->name('admin.reconciliation.run');
        Route::post('/reconciliation/suggestions/{suggestion}', [ReconciliationController::class, 'review'])->whereNumber('suggestion')->name('admin.reconciliation.review');
        Route::post('/invoices/{invoice}/void', [AdminController::class, 'void'])->whereNumber('invoice')->name('admin.void');
        Route::post('/residents/{user}', [AdminController::class, 'resident'])->name('admin.resident');
        Route::post('/dues', [AdminController::class, 'dues'])->name('admin.dues');
    });
});
