<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\NotificationService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ProfileController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Show the user's account settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/account', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's account settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('account.edit');
    }

    /**
     * Update the user's avatar.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        try {
            // Delete old avatar if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            // Optimize image: resize to max 512x512 and convert to WebP
            $image = ImageManager::gd()->read($request->file('avatar')->getPathname());
            $image->scaleDown(512, 512);
            $encoded = $image->encode(new WebpEncoder(quality: 80));

            $filename = 'avatars/'.Str::uuid().'.webp';
            Storage::disk('public')->put($filename, (string) $encoded);

            $user->update(['avatar' => $filename]);

            return to_route('account.edit')->with('flash', [
                'message' => 'Profile picture updated successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('ProfileController Avatar Update Error: '.$e->getMessage());

            return back()->with('flash', [
                'message' => 'Failed to update profile picture.',
                'type' => 'error',
            ]);
        }
    }

    /**
     * Delete the user's avatar.
     */
    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        try {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $user->update(['avatar' => null]);

            return to_route('account.edit')->with('flash', [
                'message' => 'Profile picture removed successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('ProfileController Avatar Delete Error: '.$e->getMessage());

            return back()->with('flash', [
                'message' => 'Failed to remove profile picture.',
                'type' => 'error',
            ]);
        }
    }

    /**
     * Delete the user's account (soft delete - marks for deletion).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            // Soft delete - mark for deletion instead of hard delete
            $user->update([
                'deleted_at' => now(),
                'deleted_by' => $user->id, // Self-deletion
            ]);

            // Notify admins about the deletion request
            $this->notificationService->notifyAccountDeletionRequest(
                $user->name,
                $user->name.' (self)',
                $user->id
            );

            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/')->with('status', 'Your account has been marked for deletion. An administrator will confirm the deletion.');
        } catch (\Exception $e) {
            Log::error('ProfileController Destroy Error: '.$e->getMessage());

            return back()->with('flash', [
                'message' => 'Failed to delete account. Please try again.',
                'type' => 'error',
            ]);
        }
    }
}
