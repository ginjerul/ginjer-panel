<?php

namespace Ginjer\Network\Providers;

use Ginjer\Network\Http\Controllers\StatusController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class GinjerNetworkRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            // public status pages: no login, rate limited
            Route::get('/ginjer-status/{token}', StatusController::class)
                ->where('token', '[A-Za-z0-9]{32}(\.json)?')
                ->middleware('throttle:60,1')
                ->name('ginjer-network.status');
        });
    }
}
