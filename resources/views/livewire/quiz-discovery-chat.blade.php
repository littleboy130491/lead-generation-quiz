<div class="fi-ta-content grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(22rem,.75fr)]" aria-label="AI quiz interview">
    <section class="rounded-2xl border border-gray-200 bg-gray-50/70 p-5 dark:border-white/10 dark:bg-white/5">
        <div class="flex items-start gap-3">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-600 text-lg font-bold text-white">AI</div>
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Shape the quiz together</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">Start with a rough idea. I will ask short, practical questions and turn your answers into a reviewable brief.</p>
            </div>
        </div>

        @if ($sessionId === null)
            <form wire:submit="startDiscovery" class="mt-6 space-y-3">
                <label for="discovery-opening" class="text-sm font-medium text-gray-950 dark:text-white">What are you trying to learn from potential leads?</label>
                <x-filament::input.wrapper>
                    <textarea id="discovery-opening" wire:model="opening" rows="6" class="fi-input min-h-32 w-full resize-y border-0 bg-transparent shadow-none outline-none ring-0" placeholder="For example: I want a quiz that helps independent consultants identify the growth bottleneck holding their business back."></textarea>
                </x-filament::input.wrapper>
                @error('opening') <p class="text-sm font-medium text-danger-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" icon="heroicon-m-sparkles" wire:loading.attr="disabled">Start the interview</x-filament::button>
            </form>
        @else
            <div class="mt-6 max-h-[52vh] space-y-4 overflow-y-auto pr-2" aria-live="polite">
                @foreach ($this->session()?->messages ?? [] as $message)
                    <article @class([
                        'max-w-[92%] rounded-2xl px-4 py-3 text-sm leading-6 shadow-sm',
                        'ml-auto bg-primary-600 text-white' => $message->role === 'user',
                        'border border-gray-200 bg-white text-gray-800 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100' => $message->role === 'assistant',
                    ])>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-[0.14em] opacity-70">{{ $message->role === 'assistant' ? 'Quiz assistant' : 'You' }}</p>
                        <p class="whitespace-pre-wrap">{{ $message->content }}</p>
                    </article>
                @endforeach
            </div>
            <form wire:submit="sendReply" class="mt-5 flex items-end gap-3 border-t border-gray-200 pt-5 dark:border-white/10">
                <div class="min-w-0 flex-1">
                    <label for="discovery-reply" class="sr-only">Your answer</label>
                    <x-filament::input.wrapper>
                        <textarea id="discovery-reply" wire:model="reply" rows="2" class="fi-input min-h-18 w-full resize-y border-0 bg-transparent shadow-none outline-none ring-0" placeholder="Type your answer…"></textarea>
                    </x-filament::input.wrapper>
                    @error('reply') <p class="mt-2 text-sm font-medium text-danger-600">{{ $message }}</p> @enderror
                </div>
                <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled">Send</x-filament::button>
            </form>
        @endif
    </section>

    <aside class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Reviewed brief</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Only these approved fields are used to generate the quiz.</p>
            </div>
            <span class="rounded-full bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">Human review</span>
        </div>

        <form wire:submit="saveBrief" class="mt-6 space-y-4">
            <div><label for="brief-context" class="text-sm font-medium">Business context</label><x-filament::input.wrapper class="mt-1"><textarea id="brief-context" wire:model="brief.business_context" rows="3" class="fi-input w-full resize-y border-0 bg-transparent shadow-none outline-none ring-0"></textarea></x-filament::input.wrapper></div>
            <div><label for="brief-audience" class="text-sm font-medium">Target audience</label><x-filament::input.wrapper class="mt-1"><x-filament::input id="brief-audience" wire:model="brief.target_audience" /></x-filament::input.wrapper></div>
            <div><label for="brief-objective" class="text-sm font-medium">Objective</label><x-filament::input.wrapper class="mt-1"><x-filament::input id="brief-objective" wire:model="brief.objective" /></x-filament::input.wrapper></div>
            <div><label for="brief-insight" class="text-sm font-medium">Desired insight</label><x-filament::input.wrapper class="mt-1"><x-filament::input id="brief-insight" wire:model="brief.desired_insight" /></x-filament::input.wrapper></div>
            <div class="grid grid-cols-2 gap-3"><div><label for="brief-count" class="text-sm font-medium">Questions</label><x-filament::input.wrapper class="mt-1"><x-filament::input id="brief-count" type="number" min="1" max="30" wire:model="brief.question_count" /></x-filament::input.wrapper></div><div><label for="brief-tone" class="text-sm font-medium">Tone</label><x-filament::input.wrapper class="mt-1"><x-filament::input id="brief-tone" wire:model="brief.tone" /></x-filament::input.wrapper></div></div>
            <div class="flex flex-wrap gap-3 pt-2">
                <x-filament::button type="submit" color="gray" wire:loading.attr="disabled">Save brief</x-filament::button>
                <x-filament::button type="button" wire:click="generateDraft" icon="heroicon-m-sparkles" wire:loading.attr="disabled">Generate draft</x-filament::button>
            </div>
        </form>
    </aside>
</div>
