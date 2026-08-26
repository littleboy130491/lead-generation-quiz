<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>New submission</title>
</head>
<body>
    <h1>New quiz submission</h1>
    <p><strong>Quiz:</strong> {{ $quizName }}</p>
    <p><strong>Lead email:</strong> {{ $leadEmail }}</p>
    @if ($leadName !== '')
        <p><strong>Name:</strong> {{ $leadName }}</p>
    @endif
    @if ($leadCompany !== '')
        <p><strong>Company:</strong> {{ $leadCompany }}</p>
    @endif
    @if ($leadPhone !== '')
        <p><strong>Phone:</strong> {{ $leadPhone }}</p>
    @endif
    <p><strong>Submission ID:</strong> {{ $publicId }}</p>
    <p><strong>Completed at:</strong> {{ $completedAt }}</p>
    <p><a href="{{ $adminUrl }}">Open in admin</a></p>
</body>
</html>
