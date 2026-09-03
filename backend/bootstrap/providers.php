<?php

use App\Modules\Core\Providers\ExportServiceProvider;
use App\Modules\TallySync\Providers\TallySyncEventServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AssistantServiceProvider;

return [
    AppServiceProvider::class,
    AssistantServiceProvider::class,
    ExportServiceProvider::class,
    TallySyncEventServiceProvider::class,
];
