<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsurePasswordChanged;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
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
            ->login(\App\Filament\Auth\Login::class)
            ->colors([
                'primary' => Color::Blue,
            ])
            ->brandName('نظام إدارة الإجازات — مديرية الأقصر')
            ->font('Tajawal', provider: GoogleFontProvider::class)
            ->navigationGroups([
                // Icons belong on items, not groups (Filament forbids both).
                NavigationGroup::make('المدارس'),
                NavigationGroup::make('المستخدمون'),
                NavigationGroup::make('الموظفون'),
                NavigationGroup::make('طلبات الإجازات'),
                NavigationGroup::make('الإعدادات'),
            ])
            ->navigationItems([
                NavigationItem::make('إضافة مدرسة')
                    ->icon('heroicon-o-building-office-2')
                    ->group('المدارس')
                    ->sort(1)
                    ->url(fn (): string => \App\Filament\Resources\OrganizationResource\Pages\CreateOrganization::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.organizations.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', \App\Models\Organization::class)),
                NavigationItem::make('إضافة مستخدم')
                    ->icon('heroicon-o-user-plus')
                    ->group('المستخدمون')
                    ->sort(1)
                    ->url(fn (): string => \App\Filament\Resources\UserResource\Pages\CreateUser::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.users.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', \App\Models\User::class)),
                NavigationItem::make('طلب إجازة اعتيادية')
                    ->icon('heroicon-o-document-plus')
                    ->group('طلبات الإجازات')
                    ->sort(0)
                    ->url(fn (): string => \App\Filament\Resources\LeaveRequestResource\Pages\CreateLeaveRequest::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.leave-requests.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', \App\Models\LeaveRequest::class)),
                NavigationItem::make('طلب إجازة من رصيد مرحل')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->group('طلبات الإجازات')
                    ->sort(1)
                    ->url(fn (): string => \App\Filament\Resources\LeaveRequestResource\Pages\CreateCarriedLeaveRequest::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.leave-requests.create-carried'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', \App\Models\LeaveRequest::class)),
                NavigationItem::make('إضافة موظف')
                    ->icon('heroicon-o-user-plus')
                    ->group('الموظفون')
                    ->sort(1)
                    ->url(fn (): string => \App\Filament\Resources\EmployeeResource\Pages\CreateEmployee::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.employees.create'))
                    ->visible(fn (): bool => auth()->check() && auth()->user()->can('create', \App\Models\Employee::class)),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): string => Blade::render('filament.hooks.context-bar'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChanged::class,
            ]);
    }
}
