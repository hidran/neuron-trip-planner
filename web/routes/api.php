<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TripController;
use Illuminate\Support\Facades\Route;

Route::controller(TripController::class)->prefix('trips')->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store')->middleware('throttle:10,1');
    Route::get('/{trip}', 'show');
    Route::post('/{trip}/answer', 'answer')->middleware('throttle:30,1');
    Route::post('/{trip}/retry', 'retry')->middleware('throttle:10,1');
});
