<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('leave-requests', LeaveRequestController::class)
        ->except(['destroy']);
    Route::post('/leave-requests/{leave_request}/submit', [LeaveRequestController::class, 'submit']);
    Route::post('/leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('/leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject']);
    Route::post('/leave-requests/{leave_request}/return', [LeaveRequestController::class, 'returnRequest']);
    Route::post('/leave-requests/{leave_request}/cancel', [LeaveRequestController::class, 'cancel']);

    Route::apiResource('employees', EmployeeController::class);
    Route::get('/employees/{employee}/balances', [EmployeeController::class, 'balances']);
    Route::get('/employees/{employee}/carryover-records', [EmployeeController::class, 'carryoverRecords']);

    Route::apiResource('leave-types', LeaveTypeController::class)->only(['index', 'show']);
    Route::apiResource('organizations', OrganizationController::class)->only(['index', 'show']);

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
});
