@extends('quiz.layout')

@section('title', 'Draft preview complete')

@section('content')
    @include('quiz.partials.brand')

    <section class="quiz-card quiz-complete">
        <h1>Draft preview finished</h1>
        <p>{{ $quiz->name }} is still a draft. Publish it from the admin panel when you are ready for respondents.</p>
        <div class="quiz-actions" style="justify-content: flex-start; margin-top: 1.25rem;">
            <a class="quiz-button" href="{{ url('/admin/quizzes/'.$quiz->id.'/edit') }}">Back to editor</a>
            <a class="quiz-button quiz-button-secondary" href="{{ route('quizzes.show', $quiz) }}">Preview again</a>
        </div>
    </section>
@endsection
