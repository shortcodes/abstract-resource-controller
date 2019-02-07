<?php

namespace Shortcodes\Jwt;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Shortcodes\Jwt\Services\Auth\JwtGuard;

class JwtServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Auth::extend('jwt', function ($app, $name, array $config) {
            $provider = Auth::createUserProvider($config['provider'] ?? null);
            return new JwtGuard($provider, $app->request);
        });
    }
}
