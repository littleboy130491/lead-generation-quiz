@extends('quiz.layout')

@section('title', $quiz->name)

@section('content')
    @include('quiz.partials.brand')

    <header class="quiz-header{{ $openingPending ? '' : ' quiz-header-runner' }}">
        <p class="quiz-eyebrow">{{ $openingPending ? 'Get ready' : 'Your assessment' }}</p>
        <h1>{{ $quiz->name }}</h1>
        @if ($openingPending)
            <p class="quiz-progress" aria-live="polite">Introduction</p>
        @else
            @php($progressPercent = (int) (($submission->current_page + 1) / max(count($pages), 1) * 100))
            <div class="quiz-progress-meta">
                <p class="quiz-progress" aria-live="polite">Page {{ $submission->current_page + 1 }} of {{ count($pages) }}</p>
                <p class="quiz-progress-percent">{{ $progressPercent }}%</p>
            </div>
            <div class="quiz-progress-track" aria-hidden="true"><span style="width: {{ $progressPercent }}%"></span></div>
        @endif
    </header>

    @if ($openingPending)
        <div class="quiz-card">
            <section class="quiz-opening" aria-label="Opening">
                {!! $openingHtml !!}
            </section>
            <form class="quiz-actions" method="post" action="{{ ($isDraftPreview ?? false) ? route('quizzes.draft-preview.dismiss-opening', $quiz) : route('submissions.dismiss-opening', $submission) }}">
                @csrf
                <button class="quiz-button" type="submit">{{ $opening['start_button_label'] }}</button>
            </form>
        </div>
    @else
        @php($isContentOnlyPage = $page !== [] && ! collect($page)->contains(fn ($block) => ($block['type'] ?? '') === 'question'))
        <form class="quiz-card quiz-stage{{ $isContentOnlyPage ? ' quiz-card-content-only' : '' }}" method="post" action="{{ ($isDraftPreview ?? false) ? route('quizzes.draft-preview.save-page', [$quiz, $submission->current_page]) : route('submissions.save-page', [$submission, $submission->current_page]) }}" data-quiz-form novalidate>
            @csrf
            @if ($showInlineOpening)
                <section class="quiz-opening" aria-label="Opening">
                    {!! $openingHtml !!}
                </section>
            @endif
            @if ($isContentOnlyPage)
                <div class="quiz-interlude">
                    <span class="quiz-interlude-marker" aria-hidden="true"></span>
                    <div class="quiz-interlude-copy">
                        <p class="quiz-interlude-eyebrow">A quick pause</p>
            @endif
            @php($shortcutIndex = 0)
            @foreach ($page as $block)
                @if (($block['type'] ?? '') === 'content')
                    <section class="quiz-information{{ $isContentOnlyPage ? ' quiz-information-interlude' : '' }}" aria-label="Information">
                        {!! \Illuminate\Support\Str::markdown((string) ($block['markdown'] ?? ''), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </section>
                @elseif (($block['type'] ?? '') === 'question')
                    @php($id = $block['id'])
                    @php($errorId = 'error-'.$id)
                    <fieldset class="quiz-question" aria-describedby="{{ $errors->has('answers.'.$id) ? $errorId : '' }}">
                        <legend>
                            <span class="quiz-question-lead" aria-hidden="true">→</span>
                            @if (! empty($block['icon']))
                                <span class="quiz-question-icon" aria-hidden="true">{{ $block['icon'] }}</span>
                            @endif
                            <span class="quiz-question-label">
                                {{ $block['label'] }}@if (! empty($block['required']))<span class="quiz-required" aria-hidden="true">*</span>@endif
                            </span>
                        </legend>
                        @if (! empty($block['image_url']))
                            <div class="quiz-question-media">
                                <img class="quiz-question-image" src="{{ $block['image_url'] }}" alt="">
                            </div>
                        @endif
                        @if (! empty($block['help']) || ! empty($block['help_text']))
                            <p class="quiz-help" id="help-{{ $id }}">{{ $block['help'] ?? $block['help_text'] }}</p>
                        @endif
                        @if (($block['question_type'] ?? '') === 'yes_no')
                            <div class="quiz-options">
                            @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                @php($shortcut = $shortcutIndex < 10 ? chr(65 + $shortcutIndex) : null)
                                @php($shortcutIndex++)
                                <label class="quiz-option" @if ($shortcut) data-shortcut="{{ $shortcut }}" @endif><input type="radio" name="answers[{{ $id }}]" value="{{ $value }}" @if ($shortcut) aria-keyshortcuts="{{ $shortcut }}" @endif @checked(old('answers.'.$id, data_get($submission->answers_snapshot, $id)) === $value)>@if ($shortcut)<span class="quiz-option-key" aria-hidden="true">{{ $shortcut }}</span>@endif<span class="quiz-option-label">{{ $label }}</span></label>
                            @endforeach
                            </div>
                        @elseif (($block['question_type'] ?? '') === 'single_choice')
                            <div class="quiz-options">
                            @foreach (($block['options'] ?? []) as $option)
                                @php($value = $option['value'] ?? $option['id'])
                                @php($shortcut = $shortcutIndex < 10 ? chr(65 + $shortcutIndex) : null)
                                @php($shortcutIndex++)
                                <label class="quiz-option" @if ($shortcut) data-shortcut="{{ $shortcut }}" @endif><input type="radio" name="answers[{{ $id }}]" value="{{ $value }}" @if ($shortcut) aria-keyshortcuts="{{ $shortcut }}" @endif @checked(old('answers.'.$id, data_get($submission->answers_snapshot, $id)) === $value)>@if ($shortcut)<span class="quiz-option-key" aria-hidden="true">{{ $shortcut }}</span>@endif<span class="quiz-option-label">{{ $option['label'] }}</span></label>
                            @endforeach
                            </div>
                        @elseif (($block['question_type'] ?? '') === 'multiple_choice')
                            <div class="quiz-options">
                            @foreach (($block['options'] ?? []) as $option)
                                @php($value = $option['value'] ?? $option['id'])
                                @php($shortcut = $shortcutIndex < 10 ? chr(65 + $shortcutIndex) : null)
                                @php($shortcutIndex++)
                                <label class="quiz-option" @if ($shortcut) data-shortcut="{{ $shortcut }}" @endif><input type="checkbox" name="answers[{{ $id }}][]" value="{{ $value }}" @if ($shortcut) aria-keyshortcuts="{{ $shortcut }}" @endif @checked(in_array($value, old('answers.'.$id, data_get($submission->answers_snapshot, $id, [])) ?: [], true))>@if ($shortcut)<span class="quiz-option-key" aria-hidden="true">{{ $shortcut }}</span>@endif<span class="quiz-option-label">{{ $option['label'] }}</span></label>
                            @endforeach
                            </div>
                        @elseif (($block['question_type'] ?? '') === 'long_text')
                            <textarea class="quiz-input" id="{{ $id }}" name="answers[{{ $id }}]" rows="6" maxlength="{{ $block['max_length'] ?? 5000 }}">{{ old('answers.'.$id, data_get($submission->answers_snapshot, $id)) }}</textarea>
                        @else
                            <input class="quiz-input" id="{{ $id }}" type="text" name="answers[{{ $id }}]" maxlength="{{ $block['max_length'] ?? 255 }}" value="{{ old('answers.'.$id, data_get($submission->answers_snapshot, $id)) }}">
                        @endif
                        @error('answers.'.$id)<p class="quiz-error" id="{{ $errorId }}" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
            @endforeach
            @if ($isContentOnlyPage)
                    </div>
                </div>
            @endif
            <div class="quiz-actions{{ $isContentOnlyPage ? ' quiz-actions-content-only' : '' }}">
                @if ($submission->current_page > 0 || ($opening && ! $opening['hide_start_button']))
                    <button class="quiz-button quiz-button-secondary" type="submit" name="direction" value="back">Back</button>
                @endif
                <div class="quiz-next-action">
                    <button class="quiz-button" type="submit" name="direction" value="next" data-direction-next>
                        <span>{{ $isContentOnlyPage ? ($page[0]['continue_label'] ?? 'Continue') : 'Continue' }}</span>
                        <span class="quiz-button-arrow" aria-hidden="true">→</span>
                    </button>
                    <span class="quiz-enter-hint">press <strong>Enter ↵</strong></span>
                </div>
            </div>
        </form>
    @endif
@endsection
