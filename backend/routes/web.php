<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Everything except /api/* serves the React SPA shell; React Router owns
| client-side routing from there. The negative-lookahead guard keeps this
| catch-all from ever swallowing API routes, regardless of registration
| order between routes/web.php and routes/api.php.
|
*/

Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
