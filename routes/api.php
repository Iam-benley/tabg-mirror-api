<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EmployeeSyncController;


Route::post('/employees/initialize', [EmployeeSyncController::class, 'initialize']);
Route::post('/employees/sync', [EmployeeSyncController::class, 'sync']);