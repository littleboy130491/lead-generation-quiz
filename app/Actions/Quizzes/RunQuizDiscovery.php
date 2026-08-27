<?php

namespace App\Actions\Quizzes;

use App\Ai\Discovery\QuizDiscoveryAction;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryIntent;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryPrompt;
use App\Ai\Discovery\QuizDiscoveryTurn;
use App\Models\QuizDiscoverySession;
use App\Settings\ApplicationSettings;

class RunQuizDiscovery
{
    public function __construct(
        private QuizDiscoveryInterviewer $interviewer,
        private ApplicationSettings $settings,
    ) {}

    public function start(int $userId, string $opening): QuizDiscoveryTurn
    {
        $prompts = $this->settings->get('prompts');
        $session = QuizDiscoverySession::create([
            'user_id' => $userId,
            'status' => 'interviewing',
            'brief' => [],
            'system_prompt_snapshot' => (string) ($prompts['discovery_template'] ?? QuizDiscoveryPrompt::DEFAULT_TEMPLATE),
        ]);

        return $this->reply($session, $opening, seedField: 'business_context');
    }

    public function reply(QuizDiscoverySession $session, string $message, ?string $seedField = null): QuizDiscoveryTurn
    {
        $message = trim(strip_tags($message));
        if ($message === '') {
            return new QuizDiscoveryTurn(
                $session->fresh(['messages']) ?? $session,
                QuizDiscoveryBrief::isReady($session->brief ?? []) ? QuizDiscoveryAction::Ready : QuizDiscoveryAction::Continue,
            );
        }

        $session->messages()->create(['role' => 'user', 'content' => mb_substr($message, 0, 4000)]);
        $brief = $session->brief ?? [];

        if ($seedField !== null) {
            $brief = QuizDiscoveryBrief::merge($brief, [$seedField => $message]);
        } elseif (! QuizDiscoveryIntent::isControl($message)) {
            $next = QuizDiscoveryBrief::nextMissingField($brief);
            if ($next !== null) {
                $brief = QuizDiscoveryBrief::merge($brief, [$next => $message]);
            }
        }

        $history = $session->messages()->orderBy('id')->get(['role', 'content'])->map(fn ($item) => $item->only(['role', 'content']))->all();
        $response = $this->interviewer->respond($brief, $history, $session->system_prompt_snapshot);
        $brief = QuizDiscoveryBrief::merge($brief, $response['brief']);
        $action = $response['action'] instanceof QuizDiscoveryAction
            ? $response['action']
            : QuizDiscoveryAction::fromMixed($response['action'] ?? null);

        if ($action === QuizDiscoveryAction::Execute && ! QuizDiscoveryBrief::isReady($brief)) {
            $action = QuizDiscoveryAction::Continue;
        }

        if ($action === QuizDiscoveryAction::Continue && QuizDiscoveryBrief::isReady($brief)) {
            $action = QuizDiscoveryIntent::wantsExecute($message)
                ? QuizDiscoveryAction::Execute
                : QuizDiscoveryAction::Ready;
        }

        $session->update(['brief' => $brief]);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => mb_substr($response['message'], 0, 4000),
            'brief_snapshot' => $brief,
        ]);

        return new QuizDiscoveryTurn($session->fresh(['messages']) ?? $session, $action);
    }
}
