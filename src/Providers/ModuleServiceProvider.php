<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ModuleServiceProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            $modulesPath = app_path(config('consistentapi.modules_folder', 'Modules'));

            if (! is_dir($modulesPath)) {
                return;
            }

            $prefix = config('consistentapi.api_url_prefix', '');
            $middlewares = config('consistentapi.middlewares', []);
            $dir = new \DirectoryIterator($modulesPath);

            foreach ($dir as $file) {
                if ($file->isDot() || ! $file->isDir()) {
                    continue;
                }

                $moduleName = $file->getFilename();
                if ($moduleName === 'Middleware') {
                    continue;
                }

                $moduleRoutesFile = $modulesPath . '/' . $moduleName . '/Routes.php';

                if (! file_exists($moduleRoutesFile)) {
                    continue;
                }

                $this->middleware($middlewares)
                    ->prefix($prefix)
                    ->group($moduleRoutesFile);
            }

            $globalRoutesFile = $modulesPath . '/Routes.php';

            if (file_exists($globalRoutesFile)) {
                $this->middleware($middlewares)
                    ->prefix($prefix)
                    ->group($globalRoutesFile);
            }
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('consistentapi.request_limit', 60))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
