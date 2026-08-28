<?php

namespace App\Livewire;

use App\Actions\Quizzes\ReadQuizDiscoveryConversation;
use App\Actions\Quizzes\RunQuizDiscovery;
use App\Actions\Quizzes\StopQuizDraftGeneration;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryIntent;
use App\Enums\QuizDiscoveryMode;
use App\Enums\QuizDiscoveryStatus;
use App\Enums\QuizStatus;
use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Jobs\GenerateQuizDraftJob;
use App\Models\Quiz;
use App\Models\QuizDiscoveryMessage;
use App\Models\QuizDiscoverySession;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class QuizDiscoveryChat extends Component
{
    public ?int $sessionId = null;

    public ?int $quizId = null;

    #[Locked]
    public ?int $originQuizId = null;

    #[Locked]
    public string $mode = 'create';

    public string $opening = '';

    public string $reply = '';

    public bool $showBrief = false;

    /** @var array<string, mixed> */
    public array $brief = [];

    public string $generationStatus = 'idle';

    public ?string $generatedQuizUrl = null;

    public function mount(): void
    {
        $this->mode = $this->mode === QuizDiscoveryMode::Edit->value
            ? QuizDiscoveryMode::Edit->value
            : QuizDiscoveryMode::Create->value;
        $this->originQuizId = $this->quizId;
        $query = QuizDiscoverySession::query()
            ->where('user_id', auth()->id())
            ->where('mode', $this->mode)
            ->whereIn('status', [
                QuizDiscoveryStatus::Interviewing,
                QuizDiscoveryStatus::Ready,
                QuizDiscoveryStatus::Generating,
                QuizDiscoveryStatus::Generated,
                QuizDiscoveryStatus::Failed,
                QuizDiscoveryStatus::Cancelled,
            ]);
        if ($this->originQuizId !== null) {
            $query->where('quiz_id', $this->originQuizId);
        }

        $session = $query->latest('id')->first();

        if ($session !== null) {
            $this->loadSession($session);
        }
    }

    public function startNewInterview(): void
    {
        $session = $this->session();
        if ($session?->status === QuizDiscoveryStatus::Generating) {
            return;
        }

        if ($session !== null) {
            QuizDiscoverySession::query()
                ->whereIn('id', app(ReadQuizDiscoveryConversation::class)->sessionIds($session))
                ->where('user_id', auth()->id())
                ->where('status', '!=', QuizDiscoveryStatus::Generating)
                ->update([
                    'status' => QuizDiscoveryStatus::Abandoned,
                    'updated_at' => now(),
                ]);
        }
        $this->quizId = $this->originQuizId;
        $this->reset('sessionId', 'opening', 'reply', 'brief', 'showBrief', 'generatedQuizUrl');
        $this->generationStatus = 'idle';
    }

    public function startDiscovery(?string $message = null): void
    {
        if ($message !== null) {
            $this->opening = $message;
        }

        $this->validate(['opening' => ['required', 'string', 'max:4000']]);
        $sourceQuiz = $this->mode === QuizDiscoveryMode::Edit->value
            ? Quiz::query()->findOrFail($this->originQuizId)
            : null;
        $session = app(RunQuizDiscovery::class)->start((int) auth()->id(), $this->opening, $sourceQuiz);
        if ($sourceQuiz === null && $this->originQuizId !== null) {
            $session->update(['quiz_id' => $this->originQuizId]);
        }
        $this->loadSession($session->fresh(['messages']) ?? $session);
        $this->reset('opening');
    }

    public function sendReply(?string $message = null): void
    {
        if ($message !== null) {
            $this->reply = $message;
        }

        $this->validate(['reply' => ['required', 'string', 'max:4000']]);
        $session = $this->session();
        $continuesCompletedEdit = $session?->mode === QuizDiscoveryMode::Edit
            && $session->status === QuizDiscoveryStatus::Generated;
        if ($session === null || (! $continuesCompletedEdit && ! in_array($session->status, [QuizDiscoveryStatus::Interviewing, QuizDiscoveryStatus::Ready], true))) {
            return;
        }

        $wantsImmediateGeneration = QuizDiscoveryIntent::wantsImmediateGeneration($this->reply);
        $nextSession = $continuesCompletedEdit
            ? app(RunQuizDiscovery::class)->continueEdit($session, $this->reply)
            : app(RunQuizDiscovery::class)->reply($session, $this->reply);
        $this->loadSession($nextSession);
        $this->reset('reply');

        if ($wantsImmediateGeneration && $this->session()?->status === QuizDiscoveryStatus::Ready) {
            $this->generateDraft();
        }
    }

    public function executeNow(): void
    {
        $this->generateDraft();
    }

    public function saveBrief(): void
    {
        $session = $this->session();
        if ($session === null || ! in_array($session->status, [QuizDiscoveryStatus::Interviewing, QuizDiscoveryStatus::Ready, QuizDiscoveryStatus::Failed, QuizDiscoveryStatus::Cancelled], true)) {
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
        $hasExistingEditContext = $session?->mode === QuizDiscoveryMode::Edit
            && $session->source_quiz_snapshot !== null;
        if ($session === null || (! QuizDiscoveryBrief::hasEnoughContext($brief) && ! $hasExistingEditContext)) {
            Notification::make()->danger()->title('Tell me a bit more about the quiz before I can create it.')->send();

            return;
        }

        if (in_array($session->status, [QuizDiscoveryStatus::Generating, QuizDiscoveryStatus::Generated], true)) {
            return;
        }

        if ($session->mode === QuizDiscoveryMode::Edit && ! in_array($session->status, [QuizDiscoveryStatus::Ready, QuizDiscoveryStatus::Failed, QuizDiscoveryStatus::Cancelled], true)) {
            return;
        }

        try {
            $quiz = $this->quizForGeneration($brief, $session);
            $executionToken = (string) Str::uuid();
            $claimed = QuizDiscoverySession::query()
                ->whereKey($session->id)
                ->where('user_id', auth()->id())
                ->where('mode', $this->mode)
                ->whereIn('status', [
                    QuizDiscoveryStatus::Interviewing,
                    QuizDiscoveryStatus::Ready,
                    QuizDiscoveryStatus::Failed,
                    QuizDiscoveryStatus::Cancelled,
                ])
                ->update([
                    'quiz_id' => $quiz->id,
                    'brief' => $brief,
                    'status' => QuizDiscoveryStatus::Generating,
                    'generation_token' => $executionToken,
                    'generation_started_at' => null,
                    'generation_finished_at' => null,
                    'generation_error_code' => null,
                    'generation_error_message' => null,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                $this->loadSession($session->fresh(['messages']) ?? $session);

                return;
            }

            $session = $session->fresh(['messages']) ?? $session;
            $requestedMessage = $session->mode === QuizDiscoveryMode::Edit
                ? RunQuizDiscovery::UPDATE_REQUESTED_MESSAGE
                : RunQuizDiscovery::GENERATION_REQUESTED_MESSAGE;
            if ($session->messages->last()?->content !== $requestedMessage) {
                $session->messages()->create([
                    'role' => 'assistant',
                    'content' => $requestedMessage,
                    'brief_snapshot' => $brief,
                ]);
            }
            $this->quizId = $quiz->id;
            $this->loadSession($session->fresh(['messages']) ?? $session);
            GenerateQuizDraftJob::dispatch($session->id, $executionToken);
        } catch (\Throwable $exception) {
            report($exception);
            $session->update([
                'status' => QuizDiscoveryStatus::Failed,
                'brief' => $brief,
                'generation_finished_at' => now(),
                'generation_error_code' => 'generation_dispatch_failed',
                'generation_error_message' => str($exception->getMessage())->limit(500)->toString(),
            ]);
            $session->messages()->create([
                'role' => 'assistant',
                'content' => 'Quiz draft generation could not be started. You can try again.',
                'brief_snapshot' => $brief,
            ]);
            Notification::make()
                ->danger()
                ->title('Quiz draft generation could not be started.')
                ->persistent()
                ->send();
            $this->loadSession($session->fresh(['messages']) ?? $session);
        }
    }

    public function stopGeneration(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $stopped = app(StopQuizDraftGeneration::class)->handle($session->id, (int) auth()->id());
        if ($stopped !== null) {
            $this->loadSession($stopped);
        }
    }

    public function pollGeneration(): void
    {
        $session = $this->session();
        if ($session !== null) {
            $this->loadSession($session);
        }
    }

    public function render()
    {
        return view('livewire.quiz-discovery-chat');
    }

    public function session(): ?QuizDiscoverySession
    {
        if ($this->sessionId === null) {
            return null;
        }

        $query = QuizDiscoverySession::query()
            ->whereKey($this->sessionId)
            ->where('user_id', auth()->id())
            ->where('mode', $this->mode);
        if ($this->mode === QuizDiscoveryMode::Edit->value) {
            $query->where('quiz_id', $this->originQuizId);
        }

        return $query->with('messages')->first();
    }

    /** @return Collection<int, QuizDiscoveryMessage> */
    public function conversationMessages(): Collection
    {
        $session = $this->session();

        return $session === null
            ? collect()
            : app(ReadQuizDiscoveryConversation::class)->messages($session);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function quizForGeneration(array $brief, QuizDiscoverySession $session): Quiz
    {
        if ($session->mode === QuizDiscoveryMode::Edit) {
            $quiz = Quiz::query()->find($session->quiz_id);
            if ($quiz === null || $quiz->id !== $this->originQuizId) {
                throw new \RuntimeException('The existing quiz for this edit interview could not be found.');
            }

            return $quiz;
        }

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
        $this->mode = $session->mode->value;
        $this->generationStatus = $session->status->value;
        $this->quizId = $session->quiz_id ?? $this->quizId;
        $this->generatedQuizUrl = $session->status === QuizDiscoveryStatus::Generated && $session->quiz_id !== null
            ? EditQuiz::getUrl(['record' => $session->quiz_id])
            : null;
    }
}
