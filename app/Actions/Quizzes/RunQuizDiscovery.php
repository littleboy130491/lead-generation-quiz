<?php

namespace App\Actions\Quizzes;

use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryIntent;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryPrompt;
use App\Enums\QuizDiscoveryMode;
use App\Enums\QuizDiscoveryStatus;
use App\Models\Quiz;
use App\Models\QuizDiscoverySession;
use App\Settings\ApplicationSettings;
use Illuminate\Support\Facades\Cache;

class RunQuizDiscovery
{
    /**
     * Only the most recent turns are replayed to the provider. The reviewed
     * brief already carries the durable context, so an unbounded transcript
     * would grow prompt cost and latency on every turn without adding value.
     */
    public const HISTORY_TURNS = 12;

    public const READY_MESSAGE = QuizDiscoveryPrompt::READY_MESSAGE;

    public const GENERATION_REQUESTED_MESSAGE = QuizDiscoveryPrompt::GENERATION_REQUESTED_MESSAGE;

    public const UPDATE_REQUESTED_MESSAGE = QuizDiscoveryPrompt::UPDATE_REQUESTED_MESSAGE;

    public function __construct(
        private QuizDiscoveryInterviewer $interviewer,
        private ApplicationSettings $settings,
    ) {}

    public function start(int $userId, string $opening, ?Quiz $sourceQuiz = null): QuizDiscoverySession
    {
        $prompts = $this->settings->get('prompts');
        $template = (string) ($prompts['discovery_template'] ?? QuizDiscoveryPrompt::DEFAULT_TEMPLATE);
        $mode = $sourceQuiz === null ? QuizDiscoveryMode::Create : QuizDiscoveryMode::Edit;
        $session = QuizDiscoverySession::create([
            'user_id' => $userId,
            'quiz_id' => $sourceQuiz?->id,
            'mode' => $mode,
            'status' => QuizDiscoveryStatus::Interviewing,
            'brief' => [],
            'source_quiz_snapshot' => $sourceQuiz === null ? null : $this->sourceQuizSnapshot($sourceQuiz),
            'system_prompt_snapshot' => trim($template)."\n\n".QuizDiscoveryPrompt::TURN_CONTRACT.($mode === QuizDiscoveryMode::Edit ? "\n\n".QuizDiscoveryPrompt::EDIT_TURN_CONTRACT : ''),
        ]);

        return $this->reply($session, $opening);
    }

    public function reply(QuizDiscoverySession $session, string $message): QuizDiscoverySession
    {
        $message = trim(strip_tags($message));
        if ($message === '' || ! in_array($session->status, [QuizDiscoveryStatus::Interviewing, QuizDiscoveryStatus::Ready], true)) {
            return $session->fresh(['messages']) ?? $session;
        }

        // One turn at a time per session: rapid submissions would otherwise run
        // concurrently and interleave their questions and answers.
        $turn = Cache::lock('quiz-discovery-session:'.$session->id, 300)
            ->get(fn (): QuizDiscoverySession => $this->runTurn($session, $message));

        return $turn instanceof QuizDiscoverySession
            ? $turn
            : ($session->fresh(['messages']) ?? $session);
    }

    private function runTurn(QuizDiscoverySession $session, string $message): QuizDiscoverySession
    {
        $session->messages()->create(['role' => 'user', 'content' => mb_substr($message, 0, 4000)]);
        $brief = $session->brief ?? [];
        if (QuizDiscoveryIntent::wantsImmediateGeneration($message)) {
            $ready = $this->hasEnoughContext($session, $brief);
            $assistantMessage = $ready
                ? $this->generationRequestedMessage($session)
                : 'Tell me a bit more about the quiz you want before I create it. What is the core idea?';
            $session->update([
                'brief' => $brief,
                'status' => $ready ? QuizDiscoveryStatus::Ready : QuizDiscoveryStatus::Interviewing,
            ]);
            $session->messages()->create([
                'role' => 'assistant',
                'content' => $assistantMessage,
                'brief_snapshot' => $brief,
            ]);

            return $session->fresh(['messages']) ?? $session;
        }

        $history = $session->messages()
            ->orderByDesc('id')
            ->limit(self::HISTORY_TURNS)
            ->get(['id', 'role', 'content'])
            ->sortBy('id')
            ->map(fn ($item) => $item->only(['role', 'content']))
            ->values()
            ->all();
        if ($session->mode === QuizDiscoveryMode::Edit && $session->source_quiz_snapshot !== null) {
            array_unshift($history, [
                'role' => 'user',
                'content' => '<untrusted_existing_quiz>'.json_encode($session->source_quiz_snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</untrusted_existing_quiz>',
            ]);
        }
        $response = $this->interviewer->respond($brief, $history, $session->system_prompt_snapshot);
        $brief = QuizDiscoveryBrief::merge($brief, $response['brief']);
        if ($brief === [] && $message !== '' && ! QuizDiscoveryIntent::wantsImmediateGeneration($message)) {
            $brief = QuizDiscoveryBrief::merge($brief, ['business_context' => $message]);
        }

        $action = ($response['action'] ?? 'continue') === 'generate'
            ? 'generate'
            : 'continue';
        $assistantMessage = (string) $response['message'];

        if ($action === 'generate' && ! $this->hasEnoughContext($session, $brief)) {
            $action = 'continue';
            $assistantMessage = 'Tell me a bit more about the quiz you want before I create it. What is the core idea?';
        }

        $ready = $session->mode === QuizDiscoveryMode::Edit
            ? $action === 'generate'
            : ($action === 'generate' || QuizDiscoveryBrief::isReady($brief));

        $session->update([
            'brief' => $brief,
            'status' => $ready ? QuizDiscoveryStatus::Ready : QuizDiscoveryStatus::Interviewing,
        ]);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => mb_substr($assistantMessage, 0, 4000),
            'brief_snapshot' => $brief,
        ]);

        return $session->fresh(['messages']) ?? $session;
    }

    /** @return array{name: string, description: ?string, draft_definition: array<string, mixed>} */
    private function sourceQuizSnapshot(Quiz $quiz): array
    {
        return [
            'name' => $quiz->name,
            'description' => $quiz->description,
            'draft_definition' => is_array($quiz->draft_definition)
                ? $quiz->draft_definition
                : ['schema_version' => 1, 'blocks' => []],
        ];
    }

    /** @param array<string, mixed> $brief */
    private function hasEnoughContext(QuizDiscoverySession $session, array $brief): bool
    {
        return QuizDiscoveryBrief::hasEnoughContext($brief)
            || ($session->mode === QuizDiscoveryMode::Edit && $session->source_quiz_snapshot !== null);
    }

    private function generationRequestedMessage(QuizDiscoverySession $session): string
    {
        return $session->mode === QuizDiscoveryMode::Edit
            ? self::UPDATE_REQUESTED_MESSAGE
            : self::GENERATION_REQUESTED_MESSAGE;
    }
}
