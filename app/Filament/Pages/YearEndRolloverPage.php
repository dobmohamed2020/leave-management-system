<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class YearEndRolloverPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationLabel = 'تجديد الأرصدة السنوية';
    protected static ?string $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 10;
    protected static string $view = 'filament.pages.year-end-rollover-page';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $user->setOrganizationTeam();

        return $user->can('manage_organization');
    }

    public ?array $data = [];
    public ?string $output = null;

    public function mount(): void
    {
        $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
        $this->form->fill(['year' => $fiscalYear]);
    }

    public function form(Form $form): Form
    {
        $fiscalService = app(\App\Services\FiscalYearService::class);
        $current = $fiscalService->currentFiscalYear();

        $options = collect(range($current - 2, $current + 1))
            ->mapWithKeys(fn($y) => [$y => "السنة المالية {$fiscalService->label($y)} (تبدأ 1-7 وتنتهي 30-6)"])
            ->toArray();

        return $form->schema([
            Select::make('year')
                ->label('السنة المالية المستهدفة')
                ->options($options)
                ->default($current)
                ->helperText('عملية الترحيل السنوي: تقوم بتجميع المتبقي من رصيد السنة المنتهية في 30-6 ونقله للرصيد المُرحَّل، ثم منح الرصيد السنوي الجديد بدءاً من 1-7.')
                ->required(),
        ])->statePath('data');
    }

    public function run(): void
    {
        $fiscalService = app(\App\Services\FiscalYearService::class);
        $year = $this->data['year'] ?? $fiscalService->currentFiscalYear();
        $label = $fiscalService->label($year);

        Artisan::call('leave:year-end', ['--year' => $year]);
        $this->output = Artisan::output();
        Notification::make()
            ->success()
            ->title("تم تجديد وتجميع الأرصدة للسنة المالية {$label}")
            ->send();
    }

    public function dryRun(): void
    {
        $fiscalService = app(\App\Services\FiscalYearService::class);
        $year = $this->data['year'] ?? $fiscalService->currentFiscalYear();
        $label = $fiscalService->label($year);

        Artisan::call('leave:year-end', ['--year' => $year, '--dry-run' => true]);
        $this->output = Artisan::output();
        Notification::make()
            ->info()
            ->title("معاينة تجديد الأرصدة للسنة المالية {$label} (بدون حفظ)")
            ->send();
    }
}
