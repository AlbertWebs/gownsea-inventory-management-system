<?php

use App\Http\Controllers\DarajaStkCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/daraja/stk/callback/{token}', DarajaStkCallbackController::class)
    ->where('token', '[A-Za-z0-9._~-]{32,200}')
    ->middleware('throttle:120,1')
    ->name('daraja.stk.callback');
