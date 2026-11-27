<?php

namespace App\Providers;

use App\Contracts\OutboxAmqpConnectionFactory;
use App\Contracts\OutboxPublisher;
use App\Services\Outbox\PhpAmqpConnectionFactory;
use App\Services\Outbox\RabbitMqOutboxPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OutboxAmqpConnectionFactory::class, PhpAmqpConnectionFactory::class);
        $this->app->bind(OutboxPublisher::class, RabbitMqOutboxPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
