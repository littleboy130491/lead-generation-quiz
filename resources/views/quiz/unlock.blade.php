@extends('quiz.layout')

@section('title', 'Unlock '.$quiz->name)

@section('content')
    @include('quiz.partials.brand')

    <header class="quiz-header">
        <p class="quiz-eyebrow">Private assessment</p>
        <h1>Unlock this quiz</h1>
        <p class="quiz-intro">{{ $quiz->name }}</p>
    </header>
    <form class="quiz-card" method="post" action="{{ route('quizzes.unlock', $quiz) }}">
        @csrf
        <fieldset class="quiz-question">
            <label class="quiz-field-label" for="password">Quiz password <span class="quiz-required" aria-hidden="true">*</span></label>
            <input class="quiz-input" id="password" type="password" name="password" required autocomplete="current-password" aria-describedby="password-error">
            @error('password')<p class="quiz-error" id="password-error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <div class="quiz-actions"><button class="quiz-button" type="submit">Unlock</button></div>
    </form>
@endsection
