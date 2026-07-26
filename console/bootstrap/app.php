<?php

use Illuminate\Foundation\Application;

/*
|--------------------------------------------------------------------------
| Provisioning Console — CLI-only application
|--------------------------------------------------------------------------
|
| This app is never served over HTTP: nothing ever resolves the HTTP kernel,
| so there are no routes and no middleware to configure. What we deliberately
| do NOT call, and why (do not "fix" these back in):
|
|   withRouting()    — no web/api/console route files exist. Illuminate's
|                      RoutingServiceProvider is a core default provider and
|                      still binds router/url, so nothing depends on it.
|   withMiddleware() — only registers afterResolving() hooks on the HTTP and
|                      Console kernels; with no callback it is a no-op.
|
| Application::configure() already registers both kernels, event discovery,
| bootstrap/providers.php, and Artisan command discovery for app/Console/
| Commands (see Illuminate\Foundation\Application::configure).
|
| withExceptions() IS required: it is the only place the framework binds
| Illuminate\Contracts\Debug\ExceptionHandler, which the HandleExceptions
| bootstrapper resolves on every single boot.
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions()
    ->create();
