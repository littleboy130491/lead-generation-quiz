<?php

namespace App\Actions\Quizzes;

use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryIntent;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryPrompt;
use App\Enums\QuizDiscoveryStatus;
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

    public const READY_MESSAGE = 'I have enough context. I’m generating your editable quiz draft now.';

    public function __construct(
        private QuizDiscoveryInterviewer $interviewer,
        private ApplicationSettings $settings,
    ) {}

    public function start(int $userId, string $opening): QuizDiscoverySession
    {
        $prompts = $this->settings->get('prompts');
        $template = (string) ($prompts['discovery_template'] ?? QuizDiscoveryPrompt::DEFAULT_TEMPLATE);
        $session = QuizDiscoverySession::create([
            'user_id' => $userId,
            'status' => QuizDiscoveryStatus::Interviewing,
            'brief' => [],
            'system_prompt_snapshot' => trim($template)."\n\n".QuizDiscoveryPrompt::TURN_CONTRACT,
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
            $ready = QuizDiscoveryBrief::hasEnoughContext($brief);
            $assistantMessage = $ready
                ? self::READY_MESSAGE
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
        $response = $this->interviewer->respond($brief, $history, $session->system_prompt_snapshot);
        $brief = QuizDiscoveryBrief::merge($brief, $response['brief']);
        if ($brief === [] && $message !== '' && ! QuizDiscoveryIntent::wantsImmediateGeneration($message)) {
            $brief = QuizDiscoveryBrief::merge($brief, ['business_context' => $message]);
        }

        $action = ($response['action'] ?? 'continue') === 'generate'
            ? 'generate'
            : 'continue';
        $assistantMessage = (string) $response['message'];

        // A complete brief ends the interview even when the model asks another
        // question, so a finished interview cannot stall on confirmation.
        if ($action === 'continue' && QuizDiscoveryBrief::isReady($brief)) {
            $action = 'generate';
            $assistantMessage = self::READY_MESSAGE;
        }
        if ($action === 'generate' && ! QuizDiscoveryBrief::hasEnoughContext($brief)) {
            $action = 'continue';
            $assistantMessage = 'Tell me a bit more about the quiz you want before I create it. What is the core idea?';
        }

        $session->update([
            'brief' => $brief,
            'status' => $action === 'generate' ? QuizDiscoveryStatus::Ready : QuizDiscoveryStatus::Interviewing,
        ]);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => mb_substr($assistantMessage, 0, 4000),
            'brief_snapshot' => $brief,
        ]);

        return $session->fresh(['messages']) ?? $session;
    }
}
