<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Finance\FinanceInvHeaderController;
use App\Http\Controllers\Api\Finance\FinanceInvLineController;
use App\Http\Controllers\Api\Finance\FinanceInvDocumentController;
use App\Http\Controllers\Api\SupplierFinance\SupplierInvHeaderController;
use App\Http\Controllers\Api\SupplierFinance\SupplierInvLineController;
use App\Http\Controllers\Api\Local2\LocalDataController;

Route::post('/login', [AuthController::class, 'login']);

// Route for sync data from second database
Route::get('local2/sync-inv-line', [LocalDataController::class, 'syncInvLine'])->middleware('auth:sanctum');

// Admin routes
Route::middleware(['auth:sanctum', 'userRole:1'])->prefix('super-admin')->group(function () {

    // Route for sync data from second database
    Route::get('sync-inv-line', [LocalDataController::class, 'syncInvLine']);
    Route::get('business-partners', [UserController::class, 'getBusinessPartner']);

    Route::get('index', [UserController::class, 'index']);
    Route::post('store', [UserController::class, 'store']);
    Route::get('{id}/edit', [UserController::class, 'edit']);
    Route::put('update/{id}', [UserController::class, 'update']);
    Route::delete('delete/{id}', [UserController::class, 'destroy']);
    Route::put('{id}/status/{status}', [UserController::class, 'updateStatus']);
});

// Finance routes
Route::middleware(['auth:sanctum', 'userRole:2'])->prefix('finance')->group(function () {
    Route::get('inv-header', [FinanceInvHeaderController::class, 'getInvHeader']);
    Route::put('inv-header/{inv_no}', [FinanceInvHeaderController::class, 'update']);
    Route::get('inv-line/{inv_no}', [FinanceInvLineController::class, 'getInvLine']);
    Route::get('files/{folder}/{filename}', [FinanceInvDocumentController::class, 'streamFile']);
});

// Supplier routes
Route::middleware(['auth:sanctum', 'userRole:3'])->prefix('supplier-finance')->group(function () {
    Route::get('inv-header', [SupplierInvHeaderController::class, 'getInvHeader']);
    Route::post('inv-header/store', [SupplierInvHeaderController::class, 'store']);
    Route::get('inv-line', [SupplierInvLineController::class, 'getInvLineTransaction']);
    Route::get('inv-line/{inv_no}', [SupplierInvLineController::class, 'getInvLine']);
});
