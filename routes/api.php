<?php

use App\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('timesheet')->group(function () {
    Route::get('/formulas', [TimesheetController::class, 'getFormulas']);
    Route::post('/formulas/update', [TimesheetController::class, 'updateFormula']);
    Route::get('/data', [TimesheetController::class, 'getTimesheetData']);
    Route::post('/upload', [TimesheetController::class, 'uploadTimesheet']);
    Route::get('/download', [TimesheetController::class, 'downloadFormattedTimesheet']);
    Route::delete('/clear', [TimesheetController::class, 'clearTimesheetData']);
});