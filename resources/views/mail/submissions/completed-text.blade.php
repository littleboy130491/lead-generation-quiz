New quiz submission

Quiz: {{ $quizName }}
Lead email: {{ $leadEmail }}
@if ($leadName !== '')
Name: {{ $leadName }}
@endif
@if ($leadCompany !== '')
Company: {{ $leadCompany }}
@endif
@if ($leadPhone !== '')
Phone: {{ $leadPhone }}
@endif
Submission ID: {{ $publicId }}
Completed at: {{ $completedAt }}

Open in admin: {{ $adminUrl }}
