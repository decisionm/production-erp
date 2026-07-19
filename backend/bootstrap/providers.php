<?php

use App\Modules\TallySync\Providers\TallySyncEventServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TallySyncEventServiceProvider::class,
];
