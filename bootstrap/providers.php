<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ReverbServiceProvider;
use Laravel\Reverb\ApplicationManagerServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    ApplicationManagerServiceProvider::class,
    ReverbServiceProvider::class,
];
