<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Quiz preview</title><style>body{font-family:system-ui;margin:3rem;max-width:70rem}section{border:1px solid #ddd;padding:1rem;margin:1rem 0}pre{white-space:pre-wrap}</style></head><body>
<p><a href="{{ route('admin.quizzes.history', $quiz) }}">Revision history</a> · <a href="{{ url('/admin/quizzes/'.$quiz->id.'/edit') }}">Back to editor</a></p>
<h1>{{ $quiz->name }} — {{ $revision ? 'published revision '.$revision->version : 'draft preview' }}</h1>
@if (! empty($definition['opening']['html']))
<section>
    <h2>Opening</h2>
    <div>{!! app(\App\Services\CompletionHtmlSanitizer::class)->sanitize((string) $definition['opening']['html']) !!}</div>
    @if (! ($definition['opening']['hide_start_button'] ?? false))
        <p><strong>Start button:</strong> {{ $definition['opening']['start_button_label'] ?? 'Start quiz' }}</p>
    @else
        <p><em>Start button hidden — first questions appear below the opening.</em></p>
    @endif
</section>
@endif
@if (! empty($definition['score_results']))
<section>
    <h2>Score results</h2>
    <ul>
        @foreach ($definition['score_results'] as $band)
            <li><strong>{{ $band['title'] ?? $band['id'] }}</strong> ({{ $band['min_score'] }}–{{ $band['max_score'] }})</li>
        @endforeach
    </ul>
</section>
@endif
@forelse ($pages as $number => $page)<section><h2>Page {{ $number + 1 }}</h2>@foreach ($page as $block) @if($block['type'] === 'question')<h3>{{ $block['label'] }}</h3>@if(!empty($block['image_url']))<p><img src="{{ $block['image_url'] }}" alt="{{ $block['label'] }}" style="max-width:12rem"></p>@endif @if(!empty($block['icon']))<p>Icon: {{ $block['icon'] }}</p>@endif<p><em>{{ $block['question_type'] }}</em></p>@foreach($block['options'] ?? [] as $option)<div>○ {{ $option['label'] }}@if(array_key_exists('score', $option)) <small>(score {{ $option['score'] }})</small>@endif</div>@endforeach @if(array_key_exists('yes_score', $block) || array_key_exists('no_score', $block))<p><small>Yes {{ $block['yes_score'] ?? 0 }} / No {{ $block['no_score'] ?? 0 }}</small></p>@endif @elseif($block['type'] === 'content')<pre>{{ $block['markdown'] }}</pre>@endif @endforeach</section>@empty <p>The draft has no previewable blocks.</p>@endforelse
</body></html>
