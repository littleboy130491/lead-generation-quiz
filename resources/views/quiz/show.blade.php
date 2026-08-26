@extends('quiz.layout')

@section('title', $quiz->name)

@section('content')
    @include('quiz.partials.brand')

    <header class="quiz-header">
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
            <form class="quiz-actions" method="post" action="{{ route('submissions.dismiss-opening', $submission) }}">
                @csrf
                <button class="quiz-button" type="submit">{{ $opening['start_button_label'] }}</button>
            </form>
        </div>
    @else
        <form class="quiz-card" method="post" action="{{ route('submissions.save-page', [$submission, $submission->current_page]) }}" novalidate>
            @csrf
            @if ($showInlineOpening)
                <section class="quiz-opening" aria-label="Opening">
                    {!! $openingHtml !!}
                </section>
            @endif
            @foreach ($page as $block)
                @if (($block['type'] ?? '') === 'content')
                    <section class="quiz-information" aria-label="Information">
                        {!! \Illuminate\Support\Str::markdown((string) ($block['markdown'] ?? ''), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </section>
                @elseif (($block['type'] ?? '') === 'question')
                    @php($id = $block['id'])
                    @php($errorId = 'error-'.$id)
                    <fieldset class="quiz-question" aria-describedby="{{ $errors->has('answers.'.$id) ? $errorId : '' }}">
                        <legend>
                            @if (! empty($block['icon']))
                                <span class="quiz-question-icon" aria-hidden="true">{{ $block['icon'] }}</span>
                            @endif
                            {{ $block['label'] }}
                            @if (! empty($block['required']))
                                <span class="quiz-required" aria-hidden="true">*</span>
                            @endif
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
                                <label class="quiz-option"><input type="radio" name="answers[{{ $id }}]" value="{{ $value }}" @checked(old('answers.'.$id, data_get($submission->answers_snapshot, $id)) === $value)><span>{{ $label }}</span></label>
                            @endforeach
                            </div>
                        @elseif (($block['question_type'] ?? '') === 'single_choice')
                            <div class="quiz-options">
                            @foreach (($block['options'] ?? []) as $option)
                                @php($value = $option['value'] ?? $option['id'])
                                <label class="quiz-option"><input type="radio" name="answers[{{ $id }}]" value="{{ $value }}" @checked(old('answers.'.$id, data_get($submission->answers_snapshot, $id)) === $value)><span>{{ $option['label'] }}</span></label>
                            @endforeach
                            </div>
                        @elseif (($block['question_type'] ?? '') === 'multiple_choice')
                            <div class="quiz-options">
                            @foreach (($block['options'] ?? []) as $option)
                                @php($value = $option['value'] ?? $option['id'])
                                <label class="quiz-option"><input type="checkbox" name="answers[{{ $id }}][]" value="{{ $value }}" @checked(in_array($value, old('answers.'.$id, data_get($submission->answers_snapshot, $id, [])) ?: [], true))><span>{{ $option['label'] }}</span></label>
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
            <div class="quiz-actions">
                @if ($submission->current_page > 0 || ($opening && ! $opening['hide_start_button']))
                    <button class="quiz-button quiz-button-secondary" type="submit" name="direction" value="back">Back</button>
                @endif
                <button class="quiz-button" type="submit" name="direction" value="next">{{ collect($page)->contains(fn ($block) => ($block['type'] ?? '') === 'question') ? 'Continue' : ($page[0]['continue_label'] ?? 'Continue') }}</button>
            </div>
        </form>
    @endif
@endsection
