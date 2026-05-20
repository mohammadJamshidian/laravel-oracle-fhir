<?php

namespace Teleminergmbh\OracleFhir;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Teleminergmbh\OracleFhir\Contracts\OracleFhirAuthServiceInterface;
use Teleminergmbh\OracleFhir\Contracts\OracleFhirFhirClientInterface;
use Teleminergmbh\OracleFhir\Contracts\OracleFhirHttpClientInterface;
use Teleminergmbh\OracleFhir\Contracts\OracleFhirRequestConfigResolverInterface;
use Teleminergmbh\OracleFhir\Contracts\OracleFhirTokenStoreInterface;
use Teleminergmbh\OracleFhir\Controllers\UserController;
use Teleminergmbh\OracleFhir\Http\OracleFhirHttpClient;
use Teleminergmbh\OracleFhir\Resolvers\NullOracleFhirRequestConfigResolver;
use Teleminergmbh\OracleFhir\Services\OracleFhirAuthService;
use Teleminergmbh\OracleFhir\Services\OracleFhirFhirClient;
use Teleminergmbh\OracleFhir\TokenStores\CacheTokenStore;
use Teleminergmbh\OracleFhir\TokenStores\DatabaseTokenStore;

class OracleFhirServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laravel-oracle-fhir.php' => config_path('laravel-oracle-fhir.php'),
        ], 'laravel-oracle-fhir-config');

        if (config('laravel-oracle-fhir.migrations.enabled', false)) {
            $this->publishes([
                __DIR__ . '/Database/Migrations/2026_04_16_000000_create_oracle_fhir_tokens_table.php' => database_path('migrations/2026_04_16_000000_create_oracle_fhir_tokens_table.php'),
            ], 'oracle-fhir-migrations');
        }

        if (config('laravel-oracle-fhir.routes.enabled', true)) {
            $prefix = config('laravel-oracle-fhir.routes.prefix', 'fhir/R4');
            $middleware = config('laravel-oracle-fhir.routes.middleware', 'web');

            Route::prefix($prefix)
                ->middleware($middleware)
                ->group(function () {
                    Route::get('/jwks/{clientId?}', [UserController::class, 'jwks'])->name('OracleFhir.jwks');
                    Route::get('/smart/launch/{clientId}', [UserController::class, 'smartLaunch'])->name('OracleFhir.smart.launch');
                    Route::get('/smart/callback', [UserController::class, 'smartCallback'])->name('OracleFhir.smart.callback');
                });
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-oracle-fhir.php',
            'laravel-oracle-fhir'
        );

        $this->app->bind(OracleFhirHttpClientInterface::class, OracleFhirHttpClient::class);

        $this->app->bind(OracleFhirRequestConfigResolverInterface::class, NullOracleFhirRequestConfigResolver::class);

        $this->app->singleton(OracleFhirTokenStoreInterface::class, function () {
            $driver = (string) config('laravel-oracle-fhir.token_store.driver', 'cache');

            if ($driver === 'database') {
                $connection = (string) config('laravel-oracle-fhir.token_store.database.connection', 'mysql');
                $table = (string) config('laravel-oracle-fhir.token_store.database.table', 'oracle_fhir_tokens');

                return new DatabaseTokenStore($connection, $table);
            }

            $prefix = (string) config('laravel-oracle-fhir.token_store.cache.prefix', 'oracle_fhir');
            $store = config('laravel-oracle-fhir.token_store.cache.store');

            return new CacheTokenStore($prefix, is_string($store) && $store !== '' ? $store : null);
        });

        $this->app->singleton(OracleFhirAuthServiceInterface::class, function ($app) {
            return new OracleFhirAuthService(
                $app->make(OracleFhirHttpClientInterface::class),
                $app->make(OracleFhirTokenStoreInterface::class)
            );
        });

        $this->app->singleton(OracleFhirFhirClientInterface::class, function ($app) {
            return new OracleFhirFhirClient(
                $app->make(OracleFhirHttpClientInterface::class),
                $app->make(OracleFhirAuthServiceInterface::class)
            );
        });

        $this->app->singleton(OracleFhirManager::class, function ($app) {
            return new OracleFhirManager(
                $app->make(OracleFhirHttpClientInterface::class),
                $app->make(OracleFhirTokenStoreInterface::class),
            );
        });

        $this->app->singleton(OracleFhir::class);
        $this->app->alias(OracleFhir::class, 'OracleFhir');
    }
}
