<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unlock {{ $quiz->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/quiz.css') }}">
</head>
<body class="quiz-page">
<main class="quiz-shell">
    <header class="quiz-header"><p class="quiz-eyebrow">Private assessment</p><h1>Unlock this quiz</h1><p class="quiz-intro">{{ $quiz->name }}</p></header>
    <form class="quiz-card" method="post" action="{{ route('quizzes.unlock', $quiz) }}">
        @csrf
        <fieldset class="quiz-question">
            <label class="quiz-field-label" for="password">Quiz password</label>
            <input class="quiz-input" id="password" type="password" name="password" required autocomplete="current-password" aria-describedby="password-error">
            @error('password')<p class="quiz-error" id="password-error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <div class="quiz-actions"><button class="quiz-button" type="submit">Unlock</button></div>
    </form>
</main>
</body>
</html>
