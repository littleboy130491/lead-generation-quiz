<?php

namespace App\Filament\Pages;

use App\Settings\ReportEmailSettings;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManageReportEmailSettings extends SettingsPage
{
    protected static string $settings = ReportEmailSettings::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Report email templates';

    protected static ?string $title = 'Report email templates';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sender')->schema([
                TextInput::make('from_name')->required()->maxLength(120),
                TextInput::make('reply_to')->email()->nullable()->maxLength(254),
            ])->columns(2),
            Section::make('Template')->description('Permitted placeholders: {{email}}, {{report.executive_summary}}, {{report.profile}}, {{report.disclaimer}}. HTML is escaped before report values are inserted.')->schema([
                TextInput::make('subject')->required()->maxLength(250),
                Textarea::make('html')->required()->rows(12)->maxLength(20000),
                Textarea::make('text')->required()->rows(10)->maxLength(20000),
            ]),
        ]);
    }
}
