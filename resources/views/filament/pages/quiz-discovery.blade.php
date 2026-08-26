<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-semibold">Clarify the quiz before creating it</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Share a rough idea. The assistant asks one focused follow-up at a time, then you review the structured brief before any quiz draft is generated.</p>

            @if ($sessionId === null)
                <form wire:submit="startDiscovery" class="mt-5 space-y-4">
                    <textarea wire:model="opening" rows="6" class="fi-input-wrp-input block w-full rounded-lg border-gray-300" placeholder="For example: I need a quiz that helps independent consultants identify their biggest growth bottleneck."></textarea>
                    @error('opening') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    <button type="submit" class="fi-btn fi-btn-color-primary">Start AI interview</button>
                </form>
            @else
                <div class="mt-5 space-y-3" aria-live="polite">
                    @foreach ($this->session()?->messages ?? [] as $message)
                        <div class="rounded-lg p-4 {{ $message->role === 'assistant' ? 'bg-primary-50 dark:bg-primary-950' : 'bg-gray-100 dark:bg-gray-800' }}">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide">{{ $message->role === 'assistant' ? 'Assistant' : 'You' }}</p>
                            <p class="whitespace-pre-wrap text-sm">{{ $message->content }}</p>
                        </div>
                    @endforeach
                </div>
                <form wire:submit="sendReply" class="mt-4 flex gap-3">
                    <input wire:model="reply" class="fi-input-wrp-input min-w-0 flex-1 rounded-lg border-gray-300" placeholder="Your answer…" />
                    <button type="submit" class="fi-btn fi-btn-color-primary">Send</button>
                </form>
            @endif
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-semibold">Reviewed quiz brief</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Edit this summary directly. Only these reviewed fields are sent to quiz generation—not the chat transcript.</p>
            <form wire:submit="saveBrief" class="mt-5 space-y-4">
                <label class="block text-sm font-medium">Business context<textarea wire:model="brief.business_context" rows="3" class="fi-input-wrp-input mt-1 block w-full rounded-lg border-gray-300"></textarea></label>
                <label class="block text-sm font-medium">Target audience<input wire:model="brief.target_audience" class="fi-input-wrp-input mt-1 block w-full rounded-lg border-gray-300" /></label>
                <label class="block text-sm font-medium">Objective<input wire:model="brief.objective" class="fi-input-wrp-input mt-1 block w-full rounded-lg border-gray-300" /></label>
                <label class="block text-sm font-medium">Desired insight<input wire:model="brief.desired_insight" class="fi-input-wrp-input mt-1 block w-full rounded-lg border-gray-300" /></label>
                <label class="block text-sm font-medium">Question count<input type="number" min="1" max="30" wire:model="brief.question_count" class="fi-input-wrp-input mt-1 block w-full rounded-lg border-gray-300" /></label>
                <label class="block text-sm font-medium">Tone<input wire:model="brief.tone" class="fi-input-wrp-input mt-1 block w-full rounded-lg border-gray-300" /></label>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="fi-btn fi-btn-color-gray">Save reviewed brief</button>
                    <button type="button" wire:click="generateDraft" class="fi-btn fi-btn-color-primary">Generate quiz draft</button>
                </div>
            </form>
        </section>
    </div>
</x-filament-panels::page>
