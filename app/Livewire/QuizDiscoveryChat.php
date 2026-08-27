<?php

namespace App\Livewire;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Enums\QuizStatus;
use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Models\Quiz;
use App\Models\QuizDiscoverySession;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Component;

class QuizDiscoveryChat extends Component
{
    public ?int $sessionId = null;

    public ?int $quizId = null;

    public string $opening = '';

    public string $reply = '';

    public bool $showBrief = false;

    /** @var array<string, mixed> */
    public array $brief = [];

    public function mount(): void
    {
        $session = QuizDiscoverySession::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', ['interviewing', 'ready'])
            ->latest('id')
            ->first();

        if ($session !== null) {
            $this->loadSession($session);
        }
    }

    public function startNewInterview(): void
    {
        $this->session()?->update(['status' => 'abandoned']);
        $this->reset('sessionId', 'opening', 'reply', 'brief', 'showBrief');
    }

    public function startDiscovery(?string $message = null): void
    {
        if ($message !== null) {
            $this->opening = $message;
        }

        $this->validate(['opening' => ['required', 'string', 'max:4000']]);
        $this->loadSession(app(RunQuizDiscovery::class)->start((int) auth()->id(), $this->opening));
        $this->reset('opening');
        $this->generateIfReady();
    }

    public function sendReply(?string $message = null): void
    {
        if ($message !== null) {
            $this->reply = $message;
        }

        $this->validate(['reply' => ['required', 'string', 'max:4000']]);
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $this->loadSession(app(RunQuizDiscovery::class)->reply($session, $this->reply));
        $this->reset('reply');
        $this->generateIfReady();
    }

    public function executeNow(): void
    {
        $this->generateDraft();
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
        $brief = QuizDiscoveryBrief::merge([], $this->brief ?: ($session?->brief ?? []));
        if ($session === null || ! QuizDiscoveryBrief::hasEnoughContext($brief)) {
            Notification::make()->danger()->title('Tell me a bit more about the quiz before I can create it.')->send();

            return;
        }

        if ($session->status === 'generated') {
            return;
        }

        try {
            $quiz = $this->quizForGeneration($brief);
            app(GenerateQuizDraft::class)->handle($quiz, $brief);
            $session->update(['status' => 'generated', 'brief' => $brief]);
        } catch (\Throwable $exception) {
            report($exception);
            $session->update(['status' => 'interviewing', 'brief' => $brief]);
            Notification::make()->danger()->title('Quiz draft generation could not be completed.')->send();

            return;
        }

        $this->redirect(EditQuiz::getUrl(['record' => $quiz]));
    }

    public function render()
    {
        return view('livewire.quiz-discovery-chat');
    }

    public function session(): ?QuizDiscoverySession
    {
        return $this->sessionId === null ? null : QuizDiscoverySession::query()
            ->whereKey($this->sessionId)
            ->where('user_id', auth()->id())
            ->with('messages')
            ->first();
    }

    private function generateIfReady(): void
    {
        if ($this->session()?->status === 'ready') {
            $this->generateDraft();
        }
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function quizForGeneration(array $brief): Quiz
    {
        if ($this->quizId !== null) {
            $quiz = Quiz::query()->find($this->quizId);
            if ($quiz === null) {
                throw new \RuntimeException('The quiz for this interview could not be found.');
            }

            return $quiz;
        }

        $name = filled($brief['objective'] ?? null)
            ? Str::limit((string) $brief['objective'], 80, '')
            : (filled($brief['business_context'] ?? null)
                ? Str::limit((string) $brief['business_context'], 80, '')
                : 'AI discovery quiz');

        return Quiz::query()->create([
            'name' => $name !== '' ? $name : 'AI discovery quiz',
            'slug' => Str::lower(Str::random(12)),
            'status' => QuizStatus::Draft,
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
            'settings' => [],
            'created_by' => auth()->id(),
        ]);
    }

    private function loadSession(QuizDiscoverySession $session): void
    {
        $this->sessionId = $session->id;
        $this->brief = $session->brief ?? [];
    }
}
