<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $quiz->name ?? $branding->site_name)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Source+Sans+3:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/quiz.css') }}">
    <style>
        :root {
            --quiz-primary: {{ $branding->primary_color }};
            --quiz-secondary: {{ $branding->secondary_color }};
            --quiz-background: {{ $branding->background_color }};
            --quiz-text: {{ $branding->text_color }};
            --quiz-radius: {{ $branding->border_radius }};
        }
        {!! $branding->additional_css !!}
    </style>
</head>
<body class="quiz-page @yield('body_class')">
    <div class="quiz-atmosphere" aria-hidden="true">
        <span class="quiz-orb quiz-orb-a"></span>
        <span class="quiz-orb quiz-orb-b"></span>
        <span class="quiz-orb quiz-orb-c"></span>
        <span class="quiz-grain"></span>
    </div>
    <main class="quiz-shell">
        @yield('content')
    </main>
    @if ($branding->additional_js)
        <script>{!! str_replace('</script>', '', $branding->additional_js) !!}</script>
    @endif
</body>
</html>
