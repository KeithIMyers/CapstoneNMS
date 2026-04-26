<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Reader-side accessibility preferences. The actual style toggles
 * are CSS, applied via html[data-pref-*] attributes by the inline
 * head script in layouts/site.blade.php that reads localStorage.
 *
 *   GET  /profile/accessibility       prefs page (form)
 *   POST /profile/accessibility       persist + redirect (auth users)
 *   POST /profile/accessibility/sync  JSON: read server prefs into
 *                                     localStorage on sign-in
 *
 * Authenticated readers' prefs live in users.accessibility_prefs
 * (JSON). Anonymous readers' prefs live in localStorage only;
 * server-side prefs always win when the reader signs in.
 */
class AccessibilityController extends Controller
{
    private const KEYS = [
        'high_contrast',
        'large_text',
        'dyslexia_font',
        'reduced_motion',
        'underline_links',
        'autoplay_tts',
    ];

    public function show(Request $request)
    {
        $user = $request->user();
        $prefs = $user
            ? (array) ($user->accessibility_prefs ?? [])
            : [];

        return view('pages.user.accessibility', compact('prefs'));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            // Anonymous: nothing to persist server-side. The inline
            // form stores via localStorage on submit; this branch
            // exists so a no-JS reader still gets a sane redirect
            // back to the page.
            return redirect()->route('accessibility.show')
                ->with('flash_message', 'Preferences saved on this device. Sign in to sync them across browsers.');
        }

        $request->validate([
            'high_contrast'   => 'nullable|boolean',
            'large_text'      => 'nullable|boolean',
            'dyslexia_font'   => 'nullable|boolean',
            'reduced_motion'  => 'nullable|boolean',
            'underline_links' => 'nullable|boolean',
            'autoplay_tts'    => 'nullable|boolean',
        ]);

        $prefs = [];
        foreach (self::KEYS as $key) {
            $prefs[$key] = (bool) $request->boolean($key);
        }

        $user->forceFill(['accessibility_prefs' => $prefs])->save();

        Session::flash('flash_message', 'Accessibility preferences saved.');
        return redirect()->route('accessibility.show');
    }

    /**
     * JSON sync endpoint. Called by JS shortly after sign-in to
     * pull the user's server-side prefs into localStorage so the
     * inline head-script picks them up on the next page load.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }
        return response()->json([
            'ok'    => true,
            'prefs' => (array) ($user->accessibility_prefs ?? []),
        ]);
    }
}
