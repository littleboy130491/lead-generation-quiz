<?php

namespace App\Livewire;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\ConfiguredAiProviders;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\GenerationException;
use App\Enums\QuizStatus;
use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Models\Quiz;
use App\Models\QuizDiscoverySession;
use App\Settings\ApplicationSettings;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Component;

class QuizDiscoveryChat extends Component
{
    public ?int $quizId = null;

    public ?int $sessionId = null;

    public string $opening = '';

    public string $reply = '';

    public bool $showBrief = false;

    /** @var array<string, mixed> */
    public array $brief = [];

    public function mount(?int $quizId = null): void
    {
        $this->quizId = $quizId;

        $session = QuizDiscoverySession::query()
            ->where('user_id', auth()->id())
            ->where('status', 'interviewing')
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
        $this->handleDiscoveryResult(app(RunQuizDiscovery::class)->start((int) auth()->id(), $this->opening));
        $this->reset('opening');
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

        $this->handleDiscoveryResult(app(RunQuizDiscovery::class)->reply($session, $this->reply));
        $this->reset('reply');
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
        $brief = QuizDiscoveryBrief::merge([], $this->brief);
        if ($session === null || ! QuizDiscoveryBrief::isReady($brief)) {
            Notification::make()->danger()->title('Complete the four core brief fields before generating.')->send();

            return;
        }

        try {
            $quiz = $this->resolveQuizForGeneration($brief);
            app(GenerateQuizDraft::class)->handle($quiz, $brief);
            $session->update(['status' => 'generated', 'brief' => $brief]);
        } catch (GenerationException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Quiz draft generation could not be completed.')->send();

            return;
        }

        $usedAi = app(ConfiguredAiProviders::class)->hasUsableCredentials(
            app(ApplicationSettings::class)->get('ai.quiz')
        );

        Notification::make()->success()->title(
            $usedAi ? 'AI draft generated.' : 'Structural draft generated.'
        )->send();

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

    /**
     * @param  array{session: QuizDiscoverySession, ready_to_generate: bool, execute_now: bool}  $result
     */
    private function handleDiscoveryResult(array $result): void
    {
        $this->loadSession($result['session']);

        if (! $result['ready_to_generate']) {
            return;
        }

        $this->showBrief = true;

        if ($result['execute_now'] && QuizDiscoveryBrief::isReady($this->brief)) {
            $this->generateDraft();
        }
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function resolveQuizForGeneration(array $brief): Quiz
    {
        if ($this->quizId !== null) {
            $quiz = Quiz::query()->find($this->quizId);
            if ($quiz !== null) {
                return $quiz;
            }
        }

        return Quiz::query()->create([
            'name' => Str::limit((string) $brief['objective'], 80, '') ?: 'AI discovery quiz',
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
