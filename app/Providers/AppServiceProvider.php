<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\User;
use App\Observers\EmployeeObserver;
use App\Observers\LeaveBalanceObserver;
use App\Observers\LeaveRequestObserver;
use App\Observers\OrganizationObserver;
use App\Policies\EmployeePolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\UserPolicy;
use App\Services\AuditLogService;
use App\Services\DashboardStatsService;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestService;
use App\Services\ReportPdfService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * ربط النماذج بسياسات التفويض الخاصة بها.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Organization::class => OrganizationPolicy::class,
        User::class => UserPolicy::class,
        Employee::class => EmployeePolicy::class,
        LeaveRequest::class => LeaveRequestPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(LeaveBalanceService::class);
        $this->app->singleton(LeaveRequestService::class);
        $this->app->singleton(DashboardStatsService::class);
        $this->app->singleton(ReportPdfService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ---------------------------------------------------------------
        // Fix for older MySQL versions: key length limit
        // ---------------------------------------------------------------
        Schema::defaultStringLength(191);

        // ---------------------------------------------------------------
        $this->app->resolving(JsonResponse::class, function (JsonResponse $response) {
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        });

        // ---------------------------------------------------------------
        // Arabic locale for dates everywhere (Carbon + Filament pickers)
        // ---------------------------------------------------------------
        $locale = config('app.locale', 'ar') ?: 'ar';
        Carbon::setLocale($locale);

        $configureArabicDatePicker = function (DateTimePicker $component) use ($locale): void {
            $component
                ->locale($locale)
                ->native(false)
                ->displayFormat('d F Y')
                ->firstDayOfWeek(6); // السبت
        };

        DateTimePicker::configureUsing($configureArabicDatePicker);
        DatePicker::configureUsing($configureArabicDatePicker);

        // ---------------------------------------------------------------
        // Strict mode in non-production environments:
        // prevents lazy loading, silently discarded attributes, etc.
        // ---------------------------------------------------------------
        Model::shouldBeStrict(! app()->isProduction());

        // ---------------------------------------------------------------
        // Model observers
        // ---------------------------------------------------------------
        Organization::observe(OrganizationObserver::class);
        LeaveRequest::observe(LeaveRequestObserver::class);
        LeaveBalance::observe(LeaveBalanceObserver::class);
        Employee::observe(EmployeeObserver::class);

        // ---------------------------------------------------------------
        // Policy registration
        // ---------------------------------------------------------------
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Event::listen(Logout::class, function (Logout $event): void {
            $user = $event->user;

            if ($user instanceof User) {
                app(AuditLogService::class)->recordAuth('تسجيل خروج من النظام', [
                    'ip' => request()?->ip(),
                ], $user);
            }
        });
    }
}
