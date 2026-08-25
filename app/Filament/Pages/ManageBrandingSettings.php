<?php

namespace App\Filament\Pages;

use App\Settings\BrandingSettings;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManageBrandingSettings extends SettingsPage
{
    protected static string $settings = BrandingSettings::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Branding & design';

    protected static ?string $title = 'Branding & design';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Brand identity')->schema([
                TextInput::make('site_name')->required()->maxLength(120),
                TextInput::make('eyebrow')->required()->maxLength(120),
                TextInput::make('logo_url')->url()->nullable()->maxLength(2048)->helperText('HTTPS URL to a public logo image. Leave blank to use text branding.'),
            ])->columns(2),
            Section::make('Public quiz theme')->schema([
                ColorPicker::make('primary_color')->required(), ColorPicker::make('secondary_color')->required(),
                ColorPicker::make('background_color')->required(), ColorPicker::make('text_color')->required(),
                TextInput::make('border_radius')->required()->regex('/^\\d+(?:px|rem|%)?$/')->maxLength(20),
                Textarea::make('additional_css')->rows(10)->maxLength(20000)->rules(['not_regex:/@import|url\\s*\\(|expression\\s*\\(|javascript:|<|>/i'])->helperText('Public quiz CSS only. External imports, URLs, and HTML are rejected.'),
                Textarea::make('additional_js')->rows(10)->maxLength(20000)->rules(['not_regex:/<\\/?script|<\\/?style/i'])->helperText('Trusted administrator JavaScript, loaded only on public quiz pages. Do not paste <script> tags or secrets.'),
            ])->columns(2),
        ]);
    }
}
