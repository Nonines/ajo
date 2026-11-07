<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user('web')->is_active, 403);

        return Inertia::render('Notifications', ['notifications' => $request->user('web')->notifications()->latest()->paginate(20)->through(fn ($notice): array => [
            'id' => $notice->id, 'data' => $notice->data, 'readAt' => $notice->read_at?->toIso8601String(), 'createdAt' => $notice->created_at->toIso8601String(),
        ])]);
    }

    public function update(Request $request, string $notification): RedirectResponse
    {
        abort_unless($request->user('web')->is_active, 403);
        $request->user('web')->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back();
    }
}
