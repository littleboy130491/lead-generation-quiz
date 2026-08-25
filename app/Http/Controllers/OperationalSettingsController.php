<?php

namespace App\Http\Controllers;

use App\Settings\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OperationalSettingsController extends Controller
{
    public function update(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $request->validate(['settings' => ['required', 'array']]);
        foreach ($request->input('settings', []) as $key => $json) {
            try {
                $value = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
                $settings->put((string) $key, $value);
            } catch (\JsonException|\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['settings.'.$key => $exception->getMessage()]);
            }
        }

        return back()->with('status', 'Operational settings saved.');
    }
}
