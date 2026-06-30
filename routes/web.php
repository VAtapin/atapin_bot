<?php

use App\Http\Controllers\MiniAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/family');
});

Route::get('/family', [MiniAppController::class, 'index'])->name('family.app');
