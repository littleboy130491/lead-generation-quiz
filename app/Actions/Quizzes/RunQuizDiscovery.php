<?php

namespace App\Actions\Quizzes;

use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryIntent;
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

    public function start(int $userId, string $opening): QuizDiscoverySession
    {
        $prompts = $this->settings->get('prompts');
        $template = (string) ($prompts['discovery_template'] ?? QuizDiscoveryPrompt::DEFAULT_TEMPLATE);
        $session = QuizDiscoverySession::create([
            'user_id' => $userId,
            'status' => 'interviewing',
            'brief' => [],
            'system_prompt_snapshot' => trim($template)."\n\n".QuizDiscoveryPrompt::TURN_CONTRACT,
        ]);

        return $this->reply($session, $opening);
    }

    public function reply(QuizDiscoverySession $session, string $message): QuizDiscoverySession
    {
        $message = trim(strip_tags($message));
        if ($message === '') {
            return $session->fresh(['messages']) ?? $session;
        }

        $session->messages()->create(['role' => 'user', 'content' => mb_substr($message, 0, 4000)]);
        $brief = $session->brief ?? [];
        $history = $session->messages()->orderBy('id')->get(['role', 'content'])->map(fn ($item) => $item->only(['role', 'content']))->all();
        $response = $this->interviewer->respond($brief, $history, $session->system_prompt_snapshot);
        $brief = QuizDiscoveryBrief::merge($brief, $response['brief']);
        if ($brief === [] && $message !== '' && ! QuizDiscoveryIntent::wantsImmediateGeneration($message)) {
            $brief = QuizDiscoveryBrief::merge($brief, ['business_context' => $message]);
        }

        $action = QuizDiscoveryIntent::wantsImmediateGeneration($message) || ($response['action'] ?? 'continue') === 'generate'
            ? 'generate'
            : 'continue';
        $assistantMessage = (string) $response['message'];
        if ($action === 'generate' && ! QuizDiscoveryBrief::hasEnoughContext($brief)) {
            $action = 'continue';
            $assistantMessage = 'Tell me a bit more about the quiz you want before I create it. What is the core idea?';
        }

        $session->update([
            'brief' => $brief,
            'status' => $action === 'generate' ? 'ready' : 'interviewing',
        ]);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => mb_substr($assistantMessage, 0, 4000),
            'brief_snapshot' => $brief,
        ]);

        return $session->fresh(['messages']) ?? $session;
    }
}
