<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class QuizDiscovery extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Quizzes';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'AI quiz discovery';

    protected static ?string $title = 'AI quiz discovery';

    protected string $view = 'filament.pages.quiz-discovery';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }
}
