@extends('quiz.layout')

@section('title', $quiz->name)

@section('content')
    @include('quiz.partials.brand')

    <header class="quiz-header">
        <p class="quiz-eyebrow">Draft preview</p>
        <h1>{{ $quiz->name }}</h1>
        <p class="quiz-intro">This draft does not have any previewable questions yet. Add blocks in the editor, then preview again.</p>
    </header>
    <div class="quiz-actions" style="justify-content: flex-start;">
        <a class="quiz-button" href="{{ url('/admin/quizzes/'.$quiz->id.'/edit') }}">Back to editor</a>
    </div>
@endsection
