<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\SuperAdminDashboardController;
use App\Http\Controllers\Api\Admin\SuperAdminInvHeaderController;
use App\Http\Controllers\Api\Admin\SuperAdminInvLineController;
use App\Http\Controllers\Api\Admin\SuperAdminInvDocumentController;
use App\Http\Controllers\Api\Finance\FinanceDashboardController;
use App\Http\Controllers\Api\Finance\FinanceInvHeaderController;
use App\Http\Controllers\Api\Finance\FinanceInvLineController;
use App\Http\Controllers\Api\Finance\FinanceInvDocumentController;
use App\Http\Controllers\Api\SupplierFinance\SupplierDashboardController;
use App\Http\Controllers\Api\SupplierFinance\SupplierInvHeaderController;
use App\Http\Controllers\Api\SupplierFinance\SupplierInvLineController;
use App\Http\Controllers\Api\Local2\LocalDataController;
use App\Http\Controllers\Api\Local2\InvoiceReceiptController;

Route::post('/login', [AuthController::class, 'login']);

// Route for sync data from second database
Route::get('local2/sync-inv-line', [LocalDataController::class, 'syncInvLine'])->middleware('auth:sanctum');

Route::get('sync', [InvoiceReceiptController::class, 'copyInvLines']);

// Admin routes
Route::middleware(['auth:sanctum', 'userRole:1'])->prefix('super-admin')->group(function () {
    // Dashboard
    Route::get('dashboard', [SuperAdminDashboardController::class, 'dashboard']);
    Route::get('active-user', [SuperAdminDashboardController::class, 'detailActiveUser']);
    Route::post('logout-user', [SuperAdminDashboardController::class, 'logoutByTokenId']);

    // Route for sync data from second database
    Route::get('sync-inv-line', [LocalDataController::class, 'syncInvLine']);
    Route::get('business-partners', [UserController::class, 'getBusinessPartner']);

    // User management
    Route::get('index', [UserController::class, 'index']);
    Route::post('store', [UserController::class, 'store']);
    Route::get('edit/{id}', [UserController::class, 'edit']);
    Route::put('update/{id}', [UserController::class, 'update']);
    Route::delete('delete/{id}', [UserController::class, 'destroy']);
    Route::patch('status/{id}', [UserController::class, 'updateStatus']);

    // Invoice management
    Route::get('inv-header', [SuperAdminInvHeaderController::class, 'getInvHeader']);
    Route::get('inv-header/bp-code/{bp_code}', [SuperAdminInvHeaderController::class, 'getInvHeaderByBpCode']);
    Route::post('inv-header/store', [SuperAdminInvHeaderController::class, 'store']);
    Route::put('inv-header/{inv_no}', [SuperAdminInvHeaderController::class, 'update']);
    Route::put('inv-header/in-process/{inv_no}', [SuperAdminInvHeaderController::class, 'updateStatusToInProcess']);
    Route::get('inv-header/detail/{inv_no}', [SuperAdminInvHeaderController::class, 'getInvHeaderDetail']);
    Route::post('inv-header/upload-payment/{inv_no}', [SuperAdminInvHeaderController::class, 'uploadPaymentDocument']);
    Route::get('pph', [SuperAdminInvHeaderController::class, 'getPph']);
    Route::get('ppn', [SuperAdminInvHeaderController::class, 'getPpn']);

    // Invoice lines
    Route::get('inv-line/{bp_code}', [SuperAdminInvLineController::class, 'getInvLineTransaction']);
    Route::get('inv-line/{inv_no}', [SuperAdminInvLineController::class, 'getInvLine']);
    Route::get('inv-line/outstanding/{bp_code}', [SuperAdminInvLineController::class, 'getOutstandingInvLine']);

    // Document streaming
    Route::get('files/{folder}/{filename}', [SuperAdminInvDocumentController::class, 'streamFile']);

    Route::post('logout', [AuthController::class, 'logout']);
});

// Finance routes
Route::middleware(['auth:sanctum', 'userRole:2'])->prefix('finance')->group(function () {
    // Dashboard
    Route::get('dashboard', [FinanceDashboardController::class, 'dashboard']);

    // Business Partners
    Route::get('business-partners', [UserController::class, 'getBusinessPartner']);

    // Invoice management
    Route::get('inv-header', [FinanceInvHeaderController::class, 'getInvHeader']);
    Route::get('inv-header/bp-code/{bp_code}', [FinanceInvHeaderController::class, 'getInvHeaderByBpCode']);
    Route::put('inv-header/{inv_no}', [FinanceInvHeaderController::class, 'update']);
    Route::put('inv-header/in-process/{inv_no}', [FinanceInvHeaderController::class, 'updateStatusToInProcess']);
    Route::get('inv-header/detail/{inv_no}', [FinanceInvHeaderController::class, 'getInvHeaderDetail']);
    Route::post('inv-header/upload-payment/{inv_no}', [FinanceInvHeaderController::class, 'uploadPaymentDocument']);

    // PPh
    Route::get('pph', [FinanceInvHeaderController::class, 'getPph']);

    // Invoice lines
    Route::get('inv-line/{bp_code}', [FinanceInvLineController::class, 'getInvLineTransaction']);
    Route::get('inv-line/{inv_no}', [FinanceInvLineController::class, 'getInvLine']);
    Route::get('inv-line/outstanding/{bp_code}', [FinanceInvLineController::class, 'getOutstandingInvLine']);

    // Document streaming
    Route::get('files/{folder}/{filename}', [FinanceInvDocumentController::class, 'streamFile']);

    Route::post('logout', [AuthController::class, 'logout']);
});

// Supplier routes
Route::middleware(['auth:sanctum', 'userRole:3'])->prefix('supplier-finance')->group(function () {
    // Dashboard
    Route::get('dashboard', [SupplierDashboardController::class, 'dashboard']);

    // Business Partners
    Route::get('business-partners', [SupplierDashboardController::class, 'getBusinessPartner']);

    // Invoice management
    Route::get('inv-header', [SupplierInvHeaderController::class, 'getInvHeader']);
    Route::post('inv-header/store', [SupplierInvHeaderController::class, 'store']);
    Route::get('ppn', [SupplierInvHeaderController::class, 'getPpn']);

    // Invoice lines
    Route::get('inv-line', [SupplierInvLineController::class, 'getInvLineTransaction']);
    Route::get('inv-line/{inv_no}', [SupplierInvLineController::class, 'getInvLine']);
    Route::get('inv-line/outstanding', [SupplierInvLineController::class, 'getOutstandingInvLine']);

    Route::post('logout', [AuthController::class, 'logout']);
});

// Route for sync data from second database