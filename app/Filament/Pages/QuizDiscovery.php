<?php

namespace App\Filament\Pages;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Enums\QuizStatus;
use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Models\Quiz;
use App\Models\QuizDiscoverySession;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class QuizDiscovery extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Quizzes';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'AI quiz discovery';

    protected static ?string $title = 'AI quiz discovery';

    protected string $view = 'filament.pages.quiz-discovery';

    public ?int $sessionId = null;

    public string $opening = '';

    public string $reply = '';

    /** @var array<string, mixed> */
    public array $brief = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public function mount(): void
    {
        $session = QuizDiscoverySession::query()->where('user_id', auth()->id())->latest('id')->first();
        if ($session !== null && $session->status === 'interviewing') {
            $this->loadSession($session);
        }
    }

    public function startDiscovery(): void
    {
        if (blank($this->opening)) {
            $this->addError('opening', 'Start with a short description of the quiz idea.');

            return;
        }

        $this->loadSession(app(RunQuizDiscovery::class)->start((int) auth()->id(), $this->opening));
        $this->opening = '';
    }

    public function sendReply(): void
    {
        $session = $this->session();
        if ($session === null || blank($this->reply)) {
            return;
        }

        $this->loadSession(app(RunQuizDiscovery::class)->reply($session, $this->reply));
        $this->reply = '';
    }

    public function saveBrief(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $session->update(['brief' => QuizDiscoveryBrief::merge([], $this->brief)]);
        $this->loadSession($session->fresh(['messages']));
        Notification::make()->success()->title('Reviewed brief saved.')->send();
    }

    public function generateDraft(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $brief = QuizDiscoveryBrief::merge([], $this->brief);
        if (! QuizDiscoveryBrief::isReady($brief)) {
            Notification::make()->danger()->title('Complete the four core brief fields before generating.')->send();

            return;
        }

        $quiz = Quiz::query()->create([
            'name' => Str::limit((string) $brief['objective'], 80, '') ?: 'AI discovery quiz',
            'slug' => Str::lower(Str::random(12)),
            'status' => QuizStatus::Draft,
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
            'settings' => [],
            'created_by' => auth()->id(),
        ]);
        app(GenerateQuizDraft::class)->handle($quiz, $brief);
        $session->update(['status' => 'generated', 'brief' => $brief]);

        $this->redirect(EditQuiz::getUrl(['record' => $quiz]));
    }

    public function session(): ?QuizDiscoverySession
    {
        return $this->sessionId === null ? null : QuizDiscoverySession::query()
            ->whereKey($this->sessionId)
            ->where('user_id', auth()->id())
            ->with('messages')
            ->first();
    }

    private function loadSession(QuizDiscoverySession $session): void
    {
        $this->sessionId = $session->id;
        $this->brief = $session->brief ?? [];
    }
}
