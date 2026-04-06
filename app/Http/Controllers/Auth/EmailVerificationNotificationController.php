<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (\Exception $e) {
            Log::error('Failed to send email verification notification: '.$e->getMessage());

            return back()->with('flash', [
                'message' => 'Unable to send verification email. Please try again later or contact support.',
                'type' => 'error',
            ]);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
