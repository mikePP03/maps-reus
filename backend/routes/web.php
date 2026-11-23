<?php

use App\Http\Controllers\SchedulePageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/horarios', SchedulePageController::class)->name('schedules.index');
