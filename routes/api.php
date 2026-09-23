<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlanningController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('me/photo', [AdminController::class, 'photo']);
    Route::get('creneaux', [PlanningController::class, 'creneaux']);
    Route::get('reservations', [PlanningController::class, 'index']);
    Route::post('reservations', [PlanningController::class, 'store']);
    Route::delete('reservations/{id}', [PlanningController::class, 'destroy'])->whereNumber('id');
    Route::get('planning', [PlanningController::class, 'index']);
    Route::post('planning/valider', [PlanningController::class, 'validatePlanning']);
    Route::get('planning/pdf', [PlanningController::class, 'pdf']);

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('users', [AdminController::class, 'users']);
        Route::patch('users/{id}', [AdminController::class, 'updateUser'])->whereNumber('id');
        Route::get('users/{id}/photo', [AdminController::class, 'photo'])->whereNumber('id');
        Route::get('users/{id}/planning', [AdminController::class, 'planning'])->whereNumber('id');
        Route::post('users/{id}/planning/valider', [AdminController::class, 'validatePlanning'])->whereNumber('id');
        Route::post('users/{id}/planning/deverrouiller', [AdminController::class, 'unlockPlanning'])->whereNumber('id');
        Route::get('creneaux', [AdminController::class, 'slots']);
        Route::post('reservations', [AdminController::class, 'storeReservation']);
        Route::delete('reservations/{id}', [AdminController::class, 'deleteReservation'])->whereNumber('id');
        Route::post('invitation-codes', [AdminController::class, 'invitations']);
        Route::get('export', [AdminController::class, 'export']);
    });
});
