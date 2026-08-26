@extends('quiz.layout')

@section('title', $pageTitle ?? 'Thank you')

@section('content')
    @include('quiz.partials.brand')

    <section class="quiz-card quiz-complete">
        {!! $completionHtml !!}
    </section>
@endsection
