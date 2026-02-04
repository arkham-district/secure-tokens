<?php

namespace ArkhamDistrict\ApiKeys;

use ArkhamDistrict\ApiKeys\Guards\ApiKeyGuard;
use ArkhamDistrict\ApiKeys\Middleware\AuthenticateApiKey;
use ArkhamDistrict\ApiKeys\Middleware\ValidateSignature;
use ArkhamDistrict\ApiKeys\Models\ApiKey;
use ArkhamDistrict\ApiKeys\Services\Ed25519Service;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class ApiKeysServiceProvider extends ServiceProvider
{
    /**
     * Register package services into the container.
     *
     * Merges the package configuration with the application's configuration
     * and binds Ed25519Service as a singleton.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-keys.php', 'api-keys');

        $this->app->singleton(Ed25519Service::class);
    }

    /**
     * Bootstrap package services after all providers are registered.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerGuard();
        $this->registerMiddleware();
        $this->registerRequestMacro();
    }

    /**
     * Load package database migrations.
     *
     * Migrations are loaded automatically, so consumers don't need to publish
     * them unless they want to customize the schema.
     *
     * @return void
     */
    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Register publishable assets for `vendor:publish`.
     *
     * Available publish tags:
     * - `api-keys-config`:     Publishes `config/api-keys.php`.
     * - `api-keys-migrations`: Publishes migration files to `database/migrations`.
     *
     * @return void
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/api-keys.php' => config_path('api-keys.php')], 'api-keys-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations'),], 'api-keys-migrations');
        }
    }

    /**
     * Extend Laravel's auth system with the `api-key` guard driver.
     *
     * After registration, consumers can use the guard by adding it to
     * `config/auth.php`:
     *     'guards' => [
     *         'api-key' => ['driver' => 'api-key'],
     *     ]
     *
     * @return void
     */
    protected function registerGuard(): void
    {
        Auth::extend('api-key', function (Application $app, string $name, array $config) {
            return new ApiKeyGuard($app['request']);
        });
    }

    /**
     * Register middleware aliases with the router.
     *
     * - `auth.api-key`: Authenticates requests via API key Bearer token.
     * - `verify-signature`: Validates Ed25519 signature in `X-Signature` header.
     *
     * @return void
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('auth.api-key', AuthenticateApiKey::class);
        $router->aliasMiddleware('verify-signature', ValidateSignature::class);
    }

    /**
     * Register the `apiKey()` macro on the Request class.
     *
     * Provides a convenient accessor to retrieve the authenticated ApiKey
     * model directly from the request:
     *
     *     $request->apiKey()->can('invoices:read');
     *
     * @return void
     */
    protected function registerRequestMacro(): void
    {
        Request::macro('apiKey', function (): ?ApiKey {
            /** @var ApiKeyGuard $guard */
            $guard = auth('api-key');

            return $guard->getApiKey();
        });
    }
}
