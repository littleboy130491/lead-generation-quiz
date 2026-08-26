<?php

namespace App\Filament\Pages;

use App\Settings\BrandingSettings;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class ManageBrandingSettings extends SettingsPage
{
    protected static string $settings = BrandingSettings::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Branding & design';

    protected static ?string $title = 'Branding & design';

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Brand identity')->schema([
                TextInput::make('site_name')->required()->maxLength(120),
                TextInput::make('eyebrow')->required()->maxLength(120),
                TextInput::make('logo_url')->url()->nullable()->maxLength(2048)->helperText('HTTPS URL to a public logo image. Leave blank to use text branding.'),
            ])->columns(1),
            Section::make('Public quiz theme')->schema([
                ColorPicker::make('primary_color')->required(), ColorPicker::make('secondary_color')->required(),
                ColorPicker::make('background_color')->required(), ColorPicker::make('text_color')->required(),
                TextInput::make('border_radius')->required()->regex('/^\\d+(?:px|rem|%)?$/')->maxLength(20),
                Textarea::make('additional_css')->rows(10)->maxLength(20000)->rules(['not_regex:/@import|url\\s*\\(|expression\\s*\\(|javascript:|<|>/i'])->helperText('Public quiz CSS only. External imports, URLs, and HTML are rejected.'),
                Textarea::make('additional_js')->rows(10)->maxLength(20000)->rules(['not_regex:/<\\/?script|<\\/?style/i'])->helperText('Trusted administrator JavaScript, loaded only on public quiz pages. Do not paste <script> tags or secrets.'),
            ])->columns(1),
            Section::make('Thank-you page')->description('Static HTML shown after a completed quiz. It is limited to a safe HTML subset and is never interpreted as Blade, PHP, JavaScript, or respondent data.')->schema([
                Textarea::make('completion_html')->required()->rows(14)->maxLength(40000)->helperText('Allowed: headings, paragraphs, lists, strong/emphasis, links, images, divs and spans. Scripts, forms, event attributes, inline styles, iframes, and unsafe URLs are removed when shown.'),
            ])->columns(1),
        ]);
    }
}
