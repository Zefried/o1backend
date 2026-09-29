<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AiContextController;
use App\Http\Controllers\Admin\CampaignInformationController;
use App\Http\Controllers\Admin\ChatController;
use App\Http\Controllers\Admin\AttributeFieldController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Middleware\CheckAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/register',[AuthController::class, 'register']);
Route::post('/login',[AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');


Route::post('/public/chat', [ChatController::class, 'publicChat']);

Route::prefix('admin')
    ->middleware(['auth:sanctum', CheckAdmin::class])
    ->group(function () {

        // ── User routes ───────────────────────────
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);

        // ── Business routes ───────────────────────
        Route::get('/businesses', [\App\Http\Controllers\BusinessController::class, 'index']);
        Route::post('/businesses', [\App\Http\Controllers\BusinessController::class, 'store']);
        Route::put('/businesses/{id}', [\App\Http\Controllers\BusinessController::class, 'update']);
        Route::delete('/businesses/{id}', [\App\Http\Controllers\BusinessController::class, 'destroy']);
        Route::post('/businesses/check', [\App\Http\Controllers\BusinessController::class, 'checkBusiness']);

        // ── Category routes ───────────────────────
        Route::get('/categories/flat', [CategoryController::class, 'flatList']);
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::patch('/categories/{id}/toggle', [CategoryController::class, 'toggleStatus']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // ── Service routes ────────────────────────
        Route::get('/services', [ServiceController::class, 'index']);
        Route::post('/services', [ServiceController::class, 'store']);
        Route::put('/services/{id}', [ServiceController::class, 'update']);
        Route::patch('/services/{id}/toggle', [ServiceController::class, 'toggleStatus']);
        Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

        // ── Attribute routes ──────────────────────
        Route::get('/attributes', [AttributeController::class, 'index']);
        Route::post('/attributes', [AttributeController::class, 'store']);
        Route::put('/attributes/{id}', [AttributeController::class, 'update']);
        Route::patch('/attributes/{id}/toggle', [AttributeController::class, 'toggleStatus']);
        Route::delete('/attributes/{id}', [AttributeController::class, 'destroy']);

        // ── Attribute Field routes ────────────────
        Route::get('/attribute-fields', [AttributeFieldController::class, 'index']);          // ?attribute_id=1
        Route::post('/attribute-fields', [AttributeFieldController::class, 'store']);
        Route::put('/attribute-fields/{id}', [AttributeFieldController::class, 'update']);
        Route::patch('/attribute-fields/{id}/toggle', [AttributeFieldController::class, 'toggleStatus']);
        Route::delete('/attribute-fields/{id}', [AttributeFieldController::class, 'destroy']);

        // ── AI Context routes ─────────────────────
        Route::get('/ai-contexts', [AiContextController::class, 'index']);
        Route::post('/ai-contexts', [AiContextController::class, 'store']);
        Route::put('/ai-contexts/{id}', [AiContextController::class, 'update']);
        Route::delete('/ai-contexts/{id}', [AiContextController::class, 'destroy']);

        // ── Campaign routes ───────────────────────
        Route::get('/campaigns', [CampaignInformationController::class, 'index']);
        Route::post('/campaigns', [CampaignInformationController::class, 'store']);
        Route::put('/campaigns/{id}', [CampaignInformationController::class, 'update']);
        Route::delete('/campaigns/{id}', [CampaignInformationController::class, 'destroy']);

        // ── Chat routes ───────────────────────────
        Route::post('/chat', [ChatController::class, 'handleChat']);
    });

// ── Bulk API Routes ───────────────────────────
Route::prefix('admin')
    ->middleware(['auth:sanctum', CheckAdmin::class])
    ->group(function () {
        // All bulk upload api routes should be here
        Route::post('/services/bulk', [ServiceController::class, 'bulkStore']);
        Route::post('/attributes/bulk', [AttributeController::class, 'bulkStore']);
    });