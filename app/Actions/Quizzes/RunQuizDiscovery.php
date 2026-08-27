<?php

namespace App\Actions\Quizzes;

use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryPrompt;
use App\Models\QuizDiscoverySession;
use App\Settings\ApplicationSettings;

class RunQuizDiscovery
{
    public function __construct(
        private QuizDiscoveryInterviewer $interviewer,
        private ApplicationSettings $settings,
    ) {}

    /**
     * @return array{session: QuizDiscoverySession, ready_to_generate: bool, execute_now: bool}
     */
    public function start(int $userId, string $opening): array
    {
        $prompts = $this->settings->get('prompts');
        $session = QuizDiscoverySession::create([
            'user_id' => $userId,
            'status' => 'interviewing',
            'brief' => [],
            'system_prompt_snapshot' => (string) ($prompts['discovery_template'] ?? QuizDiscoveryPrompt::DEFAULT_TEMPLATE),
        ]);

        return $this->reply($session, $opening, 'business_context');
    }

    /**
     * @return array{session: QuizDiscoverySession, ready_to_generate: bool, execute_now: bool}
     */
    public function reply(QuizDiscoverySession $session, string $message, ?string $briefField = null): array
    {
        $message = trim(strip_tags($message));
        if ($message === '') {
            return [
                'session' => $session->fresh(['messages']) ?? $session,
                'ready_to_generate' => QuizDiscoveryBrief::isReady($session->brief ?? []),
                'execute_now' => false,
            ];
        }

        $executeNow = QuizDiscoveryBrief::wantsToExecute($message);
        $session->messages()->create(['role' => 'user', 'content' => mb_substr($message, 0, 4000)]);
        $brief = QuizDiscoveryBrief::merge($session->brief ?? [], [
            $briefField ?? QuizDiscoveryBrief::nextMissingField($session->brief ?? []) ?? 'business_context' => $message,
        ]);
        $history = $session->messages()->orderBy('id')->get(['role', 'content'])->map(fn ($item) => $item->only(['role', 'content']))->all();
        $response = $this->interviewer->respond($brief, $history, $session->system_prompt_snapshot);
        $brief = QuizDiscoveryBrief::merge($brief, $response['brief']);
        $readyToGenerate = (bool) ($response['ready_to_generate'] ?? false) && QuizDiscoveryBrief::isReady($brief);

        $session->update(['brief' => $brief]);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => mb_substr($response['message'], 0, 4000),
            'brief_snapshot' => $brief,
        ]);

        return [
            'session' => $session->fresh(['messages']) ?? $session,
            'ready_to_generate' => $readyToGenerate,
            'execute_now' => $executeNow,
        ];
    }
}
