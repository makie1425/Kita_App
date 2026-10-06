<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\KitaDataController;
use App\Http\Controllers\ManagerPinController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OtpLoginController;
use App\Http\Controllers\PayMongoController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductMasterController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\SuperAdminDashboardController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('kita');
});

Route::middleware('role:super_admin')->prefix('api/backups')->group(function () {
    Route::get('/', [BackupController::class, 'index']);
    Route::post('/', [BackupController::class, 'store'])->middleware('throttle:3,1');
    Route::get('/{id}/download', [BackupController::class, 'download']);
});

Route::get('/api/kita-data', KitaDataController::class)->middleware('role:cashier,manager,admin,super_admin')->name('kita.data');
Route::middleware('role:cashier,manager')->group(function () {
    Route::get('/api/transactions/{uuid}', [TransactionController::class, 'show']);
    Route::post('/api/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('/api/transactions/{uuid}', [TransactionController::class, 'updateStatus'])->name('transactions.status');
    Route::post('/api/payments/paymongo/checkout', [PayMongoController::class, 'createCheckout'])->name('paymongo.checkout');
    Route::post('/api/payments/paymongo/{uuid}/cancel', [PayMongoController::class, 'cancelCheckout']);
});
Route::middleware('role:manager,admin')->group(function () {
    Route::post('/api/inventory/adjustments', [OperationsController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('/api/purchase-requests', [OperationsController::class, 'storeRequest'])->name('purchase-requests.store');
    Route::post('/api/purchase-orders/{id}/items', [OperationsController::class, 'addPurchaseOrderItems']);
    Route::post('/api/products', [ProductController::class, 'store'])->name('products.store');
    Route::patch('/api/products/{id}', [ProductController::class, 'update']);
    Route::post('/api/categories', [CategoryController::class, 'store']);
    Route::patch('/api/categories/{name}', [CategoryController::class, 'update']);
    Route::get('/api/accounts', [AccountController::class, 'index']);
    Route::post('/api/accounts', [AccountController::class, 'store']);
    Route::patch('/api/accounts/{role}/{id}', [AccountController::class, 'update']);
});
Route::middleware('role:admin')->group(function () {
    Route::patch('/api/inventory/adjustments/{id}', [OperationsController::class, 'updateAdjustment'])->name('adjustments.status');
    Route::patch('/api/purchase-requests/{id}', [OperationsController::class, 'updateRequest'])->name('purchase-requests.status');
});
Route::post('/api/payments/paymongo/webhook', [PayMongoController::class, 'webhook'])->name('paymongo.webhook');
Route::view('/payment/success', 'payment-success')->name('payment.success');
Route::view('/payment/cancelled', 'payment-cancelled')->name('payment.cancelled');

Route::post('/login', [OtpLoginController::class, 'login'])->middleware('guest')->name('login');
Route::get('/auth/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()])->header('Cache-Control', 'private, no-store');
})->middleware('throttle:30,1');
Route::post('/otp/request', [OtpLoginController::class, 'request'])->middleware('guest')->name('otp.request');
Route::post('/otp/verify', [OtpLoginController::class, 'verify'])->middleware('guest')->name('otp.verify');
Route::post('/logout', [OtpLoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::post('/manager/approval-pin', [ManagerPinController::class, 'store'])->middleware(['role:manager', 'throttle:5,1']);

Route::get('/api/super-admin/dashboard', SuperAdminDashboardController::class)->middleware('role:super_admin');

Route::middleware('role:manager,admin')->group(function () {
    Route::get('/api/purchasing', [PurchasingController::class, 'index']);
    Route::post('/api/suppliers', [PurchasingController::class, 'supplier']);
    Route::get('/purchase-orders/{id}/report', [PurchasingController::class, 'report']);
});
Route::post('/api/purchase-orders/{id}/receive', [PurchasingController::class, 'receive'])->middleware('role:manager');

Route::middleware('role:cashier,manager,admin,super_admin')->group(function () {
    Route::get('/api/notifications', [NotificationController::class, 'index']);
    Route::patch('/api/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/api/notifications/{id}/read', [NotificationController::class, 'read']);
});

Route::middleware('role:manager,admin,super_admin')->group(function () {
    Route::post('/api/product-master/subcategories/populate', [ProductMasterController::class, 'populateSubcategories']);
    Route::post('/api/product-master/brands/populate', [ProductMasterController::class, 'populateBrands']);
    Route::post('/api/product-master/{kind}', [ProductMasterController::class, 'save'])->whereIn('kind', ['brands', 'subcategories']);
    Route::patch('/api/product-master/{kind}/{id}', [ProductMasterController::class, 'save'])->whereIn('kind', ['brands', 'subcategories'])->whereNumber('id');
    Route::get('/api/inventory-history', [ProductMasterController::class, 'inventory']);
});
