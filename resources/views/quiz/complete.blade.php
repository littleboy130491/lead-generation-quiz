<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? 'Thank you' }}</title>
    <link rel="stylesheet" href="{{ asset('css/quiz.css') }}">
</head>
<body class="quiz-page">
<main class="quiz-shell">
    <section class="quiz-card quiz-complete">
        {!! $completionHtml !!}
    </section>
</main>
</body>
</html>
