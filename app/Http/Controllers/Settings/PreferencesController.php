<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PreferencesController extends Controller
{
    /**
     * Show the preferences form.
     */
    public function edit(Request $request)
    {
        return Inertia::render('settings/preferences', [
            'user' => $request->user()->only(['time_format']),
        ]);
    }

    /**
     * Update the user's preferences.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'time_format' => 'required|in:12,24',
        ]);

        $request->user()->update($validated);

        return redirect()->back()->with('success', 'Preferences updated successfully.');
    }
}
