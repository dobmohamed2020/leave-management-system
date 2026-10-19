<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\LeaveRequestResource\Pages\CreateCarriedLeaveRequest;
use App\Filament\Resources\LeaveRequestResource\Pages\CreateLeaveRequest;
use App\Filament\Resources\OrganizationResource\Pages\CreateOrganization;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\NormalizeArabicNumbers;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\User;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Blue,
            ])
            ->brandName('نظام إدارة الإجازات — مديرية الأقصر')
            ->font('Tajawal', provider: GoogleFontProvider::class)
            ->navigationGroups([
                // Icons belong on items, not groups (Filament forbids both).
                // Resources must belong to a registered group or they won't appear in the sidebar.
                NavigationGroup::make('المدارس'),
                NavigationGroup::make('المؤسسات والمستخدمون'),
                NavigationGroup::make('التقارير'),
                NavigationGroup::make('المستخدمون'),
                NavigationGroup::make('الموظفون'),
                NavigationGroup::make('طلبات الإجازات'),
                NavigationGroup::make('الإعدادات'),
                NavigationGroup::make('النظام'),
            ])
            ->navigationItems([
                NavigationItem::make('إضافة مدرسة')
                    ->icon('heroicon-o-building-office-2')
                    ->group('المدارس')
                    ->sort(1)
                    ->url(fn (): string => CreateOrganization::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.organizations.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', Organization::class)),
                NavigationItem::make('إضافة مستخدم')
                    ->icon('heroicon-o-user-plus')
                    ->group('المستخدمون')
                    ->sort(1)
                    ->url(fn (): string => CreateUser::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.users.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', User::class)),
                NavigationItem::make('طلب إجازة اعتيادية')
                    ->icon('heroicon-o-document-plus')
                    ->group('طلبات الإجازات')
                    ->sort(0)
                    ->url(fn (): string => CreateLeaveRequest::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.leave-requests.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', LeaveRequest::class)),
                NavigationItem::make('طلب إجازة من رصيد مرحل')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->group('طلبات الإجازات')
                    ->sort(1)
                    ->url(fn (): string => CreateCarriedLeaveRequest::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.leave-requests.create-carried'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', LeaveRequest::class)),
                NavigationItem::make('إضافة موظف')
                    ->icon('heroicon-o-user-plus')
                    ->group('الموظفون')
                    ->sort(1)
                    ->url(fn (): string => CreateEmployee::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.employees.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', Employee::class)),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): string => Blade::render('filament.hooks.context-bar'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('filament.hooks.english-numerals'),
            )
            ->middleware([
                NormalizeArabicNumbers::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                LogUserActivity::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChanged::class,
            ]);
    }
}
