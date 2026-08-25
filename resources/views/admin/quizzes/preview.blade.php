<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Quiz preview</title><style>body{font-family:system-ui;margin:3rem;max-width:70rem}section{border:1px solid #ddd;padding:1rem;margin:1rem 0}pre{white-space:pre-wrap}</style></head><body>
<p><a href="{{ route('admin.quizzes.history', $quiz) }}">Revision history</a> · <a href="{{ url('/admin/quizzes/'.$quiz->id.'/edit') }}">Back to editor</a></p>
<h1>{{ $quiz->name }} — {{ $revision ? 'published revision '.$revision->version : 'draft preview' }}</h1>
@forelse ($pages as $number => $page)<section><h2>Page {{ $number + 1 }}</h2>@foreach ($page as $block) @if($block['type'] === 'question')<h3>{{ $block['label'] }}</h3><p><em>{{ $block['question_type'] }}</em></p>@foreach($block['options'] ?? [] as $option)<div>○ {{ $option['label'] }}</div>@endforeach @elseif($block['type'] === 'content')<pre>{{ $block['markdown'] }}</pre>@endif @endforeach</section>@empty <p>The draft has no previewable blocks.</p>@endforelse
</body></html>