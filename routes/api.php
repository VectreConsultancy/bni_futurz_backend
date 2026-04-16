<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\ResponsibilityController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\UserController;

Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/auth/login', [AuthController::class, 'login'])->name('login');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Protected Routes
Route::middleware(['auth:sanctum', 'check_status'])->group(function () {
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::get('/coordinator-categories', [MasterDataController::class, 'getCategories']);
    
    // Responsibility APIs
    Route::get('/responsibilities', [ResponsibilityController::class, 'index']);
    Route::post('/responsibilities', [ResponsibilityController::class, 'store']);
    Route::get('/responsibilities/{id}', [ResponsibilityController::class, 'show']);
    Route::put('/responsibilities/{id}', [ResponsibilityController::class, 'update']);
    // Route::delete('/responsibilities/{id}', [ResponsibilityController::class, 'destroy']);
    Route::get('/role-responsibilities', [ResponsibilityController::class, 'getMyRoleResponsibilities']);

    // Event APIs
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);

    // User-Specific APIs (Auth Specific)
    Route::prefix('user')->group(function () {
        Route::get('/responsibilities/basic', [UserController::class, 'getMyBasicResponsibilities']);
        Route::get('/responsibilities/events', [UserController::class, 'getMyEventResponsibilities']);
        Route::post('update-basic-checklist', [UserController::class, 'updateBasicChecklist']);
        Route::post('update-checklist/{id}', [UserController::class, 'updateEventChecklist']);
    });

    // User Management APIs (Admin)
    Route::get('/user-assignments', [UserController::class, 'getUsersWithAssignments']);
    Route::post('/user', [UserController::class, 'storeUser']);
    Route::post('/user/{id}', [UserController::class, 'updateUser']);
    Route::post('/user/toggle-status/{id}', [UserController::class, 'toggleUserStatus']);
});
