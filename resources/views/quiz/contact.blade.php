@extends('quiz.layout')

@section('title', 'Your report')

@section('content')
    @include('quiz.partials.brand')

    <header class="quiz-header">
        <p class="quiz-eyebrow">Almost there</p>
        <h1>Where should we send your report?</h1>
        <p class="quiz-intro">Add your details and we’ll prepare your tailored next steps.</p>
    </header>
    @if (is_array($scoreResult ?? null))
        <section class="quiz-card quiz-score-result" aria-label="Your result">
            <h2>{{ $scoreResult['title'] ?? 'Your result' }}</h2>
            @if ($scoreResultHtml !== '')
                <div class="quiz-opening">{!! $scoreResultHtml !!}</div>
            @endif
        </section>
    @endif
    <form class="quiz-card" method="post" action="{{ route('submissions.finalize', $submission) }}">
        @csrf
        <div class="quiz-honeypot" aria-hidden="true"><label>Leave this blank <input tabindex="-1" name="website" autocomplete="off"></label></div>
        <fieldset class="quiz-question">
            <label class="quiz-field-label" for="email">Email address <span class="quiz-required" aria-hidden="true">*</span></label>
            <input class="quiz-input" id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-describedby="email-error">
            @error('email')<p class="quiz-error" id="email-error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        @if ($quiz->collectsContactField('name'))
            <fieldset class="quiz-question">
                <label class="quiz-field-label" for="name">Name <span>(optional)</span></label>
                <input class="quiz-input" id="name" name="name" value="{{ old('name') }}" autocomplete="name">
            </fieldset>
        @endif
        @if ($quiz->collectsContactField('company'))
            <fieldset class="quiz-question">
                <label class="quiz-field-label" for="company">Company <span>(optional)</span></label>
                <input class="quiz-input" id="company" name="company" value="{{ old('company') }}" autocomplete="organization">
            </fieldset>
        @endif
        @if ($quiz->collectsContactField('phone'))
            <fieldset class="quiz-question">
                <label class="quiz-field-label" for="phone">Phone <span>(optional)</span></label>
                <input class="quiz-input" id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel">
            </fieldset>
        @endif
        <div class="quiz-actions"><button class="quiz-button" type="submit">Send my report</button></div>
    </form>
@endsection
