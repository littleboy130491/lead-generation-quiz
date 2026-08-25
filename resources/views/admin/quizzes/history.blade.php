<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Revision history</title><style>body{font-family:system-ui;margin:3rem;max-width:70rem}li{margin:.7rem 0}</style></head><body>
<p><a href="{{ route('admin.quizzes.preview', $quiz) }}">Preview draft</a> · <a href="{{ url('/admin/quizzes/'.$quiz->id.'/edit') }}">Back to editor</a></p>
<h1>{{ $quiz->name }} — revision history</h1><p>Published revisions are immutable. Each link previews its frozen definition.</p>
<ol>@forelse($revisions as $revision)<li><strong>Version {{ $revision->version }}</strong>, published {{ $revision->published_at }} <a href="{{ route('admin.quizzes.preview', ['quiz' => $quiz, 'revision' => $revision->id]) }}">Preview frozen revision</a></li>@empty<li>No published revisions yet.</li>@endforelse</ol>
</body></html>