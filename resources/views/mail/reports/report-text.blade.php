YOUR QUIZ REPORT

{{ $report['executive_summary'] }}

Profile: {{ $report['profile'] }}
@foreach (['strengths' => 'Strengths', 'challenges' => 'Challenges', 'recommendations' => 'Recommendations', 'action_plan' => 'Action plan'] as $field => $heading)

{{ $heading }}
@foreach ($report[$field] as $item)
- {{ $item['title'] }}: {{ $item['detail'] }}
@endforeach
@endforeach

{{ $report['disclaimer'] }}
