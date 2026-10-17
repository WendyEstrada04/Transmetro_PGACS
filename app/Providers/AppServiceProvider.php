<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publicado en la nube: todos los enlaces y estilos se generan con https (sin contenido mixto).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
