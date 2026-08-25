<x-filament-panels::page>
    <p class="text-sm text-gray-600">Store operational configuration here. Provider credentials remain environment-only and are never displayed or saved.</p>
    @if (session('status'))<p class="mt-4 text-sm text-success-600">{{ session('status') }}</p>@endif
    <form class="mt-6 space-y-6" method="POST" action="{{ route('admin.operational-settings.update') }}">
        @csrf
        @method('PUT')
        @php($labels = ['ai.quiz' => 'Quiz AI provider chain', 'ai.report' => 'Report AI provider chain', 'prompts' => 'Prompt templates and version labels', 'report.email' => 'Report email subject and templates', 'design' => 'Design tokens and Additional CSS', 'spam' => 'Spam policy and Turnstile', 'operations' => 'Resume, retention, retry, and timeout policies'])
        @foreach ($labels as $key => $label)
            <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h2 class="font-semibold">{{ $label }}</h2>
                <label class="mt-3 block text-sm text-gray-600" for="settings-{{ str_replace('.', '-', $key) }}">Structured JSON</label>
                <textarea id="settings-{{ str_replace('.', '-', $key) }}" name="settings[{{ $key }}]" rows="5" class="mt-1 block w-full rounded border-gray-300 font-mono text-sm dark:border-gray-700">{{ json_encode(old('settings.'.$key, $settings[$key]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                @error('settings.'.$key)<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
            </section>
        @endforeach
        <x-filament::button type="submit">Save operational settings</x-filament::button>
    </form>
</x-filament-panels::page>
