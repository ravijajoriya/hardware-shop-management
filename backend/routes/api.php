<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillController;
use App\Http\Controllers\Admin\BillReturnController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\StockCorrectionController;
use App\Http\Controllers\Admin\StockEntryController;
use App\Http\Controllers\Admin\StockHistoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('admin')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Read-only: entries are written by the actions themselves and never changed.
        Route::get('/activity-logs/options', [ActivityLogController::class, 'options']);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('brands', BrandController::class);

        // Registered before the resource so "options" is not read as a product id.
        Route::get('/products/options', [ProductController::class, 'options']);
        Route::get('/products/import/template', [ProductImportController::class, 'template']);
        Route::post('/products/import/check', [ProductImportController::class, 'check']);
        Route::post('/products/import', [ProductImportController::class, 'store']);
        Route::apiResource('products', ProductController::class);
        Route::get('/products/{product}/stock-history', [StockHistoryController::class, 'show']);

        // Stock in: deliveries are logged and added to stock. They are not edited; one entered
        // by mistake is deleted, which takes its stock back out.
        Route::get('/stock-entries/suppliers', [StockEntryController::class, 'suppliers']);
        Route::apiResource('stock-entries', StockEntryController::class)->except('update');
        Route::post('/product-variants/{variant}/stock-corrections', [StockCorrectionController::class, 'store']);
        Route::post('/products/{product}/images', [ProductImageController::class, 'store']);
        Route::put('/products/{product}/images/order', [ProductImageController::class, 'reorder']);
        Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
            ->scopeBindings();

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/reports/sales', [ReportController::class, 'sales']);

        // Bills are never deleted: a wrong one is edited in place or cancelled, and every change
        // is in the activity log. Dues are paid on one bill, or through the customer, which
        // clears their oldest bills first.
        Route::get('/bills', [BillController::class, 'index']);
        Route::post('/bills', [BillController::class, 'store']);
        Route::get('/bills/{bill}', [BillController::class, 'show']);
        Route::get('/bills/{bill}/pdf', [BillController::class, 'pdf']);
        Route::put('/bills/{bill}', [BillController::class, 'update']);
        Route::post('/bills/{bill}/cancel', [BillController::class, 'cancel']);
        Route::post('/bills/{bill}/payments', [BillController::class, 'receivePayment']);
        Route::post('/bills/{bill}/returns', [BillReturnController::class, 'store']);

        // Registered before {customer} so "lookup" is not read as a customer id.
        Route::get('/customers/lookup', [CustomerController::class, 'lookup']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::post('/customers/{customer}/payments', [CustomerController::class, 'payDues']);
    });
});
