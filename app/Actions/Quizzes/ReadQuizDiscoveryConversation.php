<?php

namespace App\Actions\Quizzes;

use App\Enums\QuizDiscoveryMode;
use App\Models\QuizDiscoveryMessage;
use App\Models\QuizDiscoverySession;
use Illuminate\Database\Eloquent\Collection;

class ReadQuizDiscoveryConversation
{
    private const MAX_CYCLES = 50;

    /** @return list<int> */
    public function sessionIds(QuizDiscoverySession $session): array
    {
        $ids = [];
        $visited = [];
        $current = $session;

        while ($current !== null && count($ids) < self::MAX_CYCLES && ! isset($visited[$current->id])) {
            $visited[$current->id] = true;
            array_unshift($ids, $current->id);
            $parentId = $current->continued_from_session_id;
            if ($parentId === null) {
                break;
            }

            $parent = QuizDiscoverySession::query()
                ->whereKey($parentId)
                ->where('user_id', $session->user_id)
                ->where('mode', $session->mode)
                ->when(
                    $session->mode === QuizDiscoveryMode::Edit,
                    fn ($query) => $query->where('quiz_id', $session->quiz_id),
                )
                ->first();
            $current = $parent;
        }

        return $ids;
    }

    /** @return Collection<int, QuizDiscoveryMessage> */
    public function messages(QuizDiscoverySession $session): Collection
    {
        return QuizDiscoveryMessage::query()
            ->whereIn('quiz_discovery_session_id', $this->sessionIds($session))
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{role: string, content: string}> */
    public function recentHistory(QuizDiscoverySession $session, int $limit): array
    {
        return QuizDiscoveryMessage::query()
            ->whereIn('quiz_discovery_session_id', $this->sessionIds($session))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'role', 'content'])
            ->sortBy('id')
            ->map(fn (QuizDiscoveryMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }
}
