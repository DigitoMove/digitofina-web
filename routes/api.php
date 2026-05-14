<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('audit.api')
    ->group(base_path('routes/api/v1.php'));
