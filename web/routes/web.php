<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The React SPA owns every non-API URL and routes on the client.
Route::view('/{any?}', 'app')->where('any', '^(?!api/).*$');
