<?php

namespace App\Providers;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Support\AuditTrail;
use App\Support\DemoClock;
use App\Support\FinancialTransaction;
use App\Support\PaymentGateways;
use App\Support\SecurityAudit;
use App\Support\SystemClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class, fn () => app()->environment('demo') ? new DemoClock : new SystemClock);
        // Keep held-lock and audit-writer state shared within one request or job, then discard it.
        $this->app->scoped(FinancialTransaction::class);
        $this->app->scoped(AuditTrail::class);
        $this->app->bind(PaymentGateway::class, PaymentGateways::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(JobExceptionOccurred::class, function (): void {
            if (app()->environment('demo')) {
                DB::table('demo_state')->where('id', 1)->update(['failure' => 'A demo job failed. Inspect application logs, then resume.']);
            }
        });
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'sqlite') {
                // Deletion triggers deny audit removal unless verified maintenance temporarily enables it.
                $event->connection->getPdo()->sqliteCreateFunction('audit_maintenance', static fn (): int => 0);
            }
        });
        SecurityAudit::register();
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
