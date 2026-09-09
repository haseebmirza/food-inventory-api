<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', HealthController::class)->name('health');

// Swagger UI: served as static files from public/docs/
// Visit /docs/index.html (or configure nginx/Herd to serve /docs → /docs/index.html)
