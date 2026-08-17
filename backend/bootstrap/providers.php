<?php

use App\Modules\Core\Providers\ExportServiceProvider;
use App\Modules\TallySync\Providers\TallySyncEventServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ExportServiceProvider::class,
    TallySyncEventServiceProvider::class,
];
