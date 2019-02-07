<?php

namespace Shortcodes\Jwt;

use Illuminate\Support\ServiceProvider;

class AbstractResourceControllerProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->make('Shortcodes\AbstractResourceController\Controllers\AbstractResourceControllerController');
    }
}
