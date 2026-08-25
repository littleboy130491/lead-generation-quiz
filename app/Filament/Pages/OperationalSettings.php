<?php

namespace App\Filament\Pages;

use App\Settings\ApplicationSettings;
use Filament\Pages\Page;

class OperationalSettings extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Operational settings';

    protected static ?string $title = 'Operational settings';

    protected string $view = 'filament.pages.operational-settings';

    protected function getViewData(): array
    {
        return ['settings' => app(ApplicationSettings::class)->all()];
    }
}
