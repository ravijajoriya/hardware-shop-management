<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
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

        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('brands', BrandController::class);

        // Registered before the resource so "options" is not read as a product id.
        Route::get('/products/options', [ProductController::class, 'options']);
        Route::apiResource('products', ProductController::class);
        Route::post('/products/{product}/images', [ProductImageController::class, 'store']);
        Route::put('/products/{product}/images/order', [ProductImageController::class, 'reorder']);
        Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
            ->scopeBindings();

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        // Bills are never edited or deleted once made; dues are paid through the customer.
        Route::get('/bills', [BillController::class, 'index']);
        Route::post('/bills', [BillController::class, 'store']);
        Route::get('/bills/{bill}', [BillController::class, 'show']);

        // Registered before {customer} so "lookup" is not read as a customer id.
        Route::get('/customers/lookup', [CustomerController::class, 'lookup']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::post('/customers/{customer}/payments', [CustomerController::class, 'payDues']);
    });
});
