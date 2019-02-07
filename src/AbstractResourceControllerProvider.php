<?php

namespace Shortcodes\AbstractResourceController;

use Illuminate\Support\ServiceProvider;

class AbstractResourceControllerProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->make('Shortcodes\AbstractResourceController\Controllers\AbstractResourceController');
    }
}
