<?php

use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The frontend is a standalone Vite/React project (../frontend) built
| directly into public/build/. Everything except /api/* serves that
| build's index.html as-is; React Router owns client-side routing from
| there. Static assets under /build/assets/* are real files and get
| served directly by the webserver — they never reach this route. The
| negative-lookahead guard keeps this catch-all from ever swallowing API
| routes, regardless of registration order between routes/web.php and
| routes/api.php.
|
*/

Route::get('/{any}', function () {
    $index = public_path('build/index.html');

    abort_unless(is_file($index), 500, 'Frontend build not found — run `npm run build` in /frontend.');

    return Response::make(file_get_contents($index), 200, ['Content-Type' => 'text/html']);
})->where('any', '^(?!api).*$');
