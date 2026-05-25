<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'readCount' => $request->user()->readNotifications()->count(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $this->notificationForUser($request, $notification);
        $record->markAsRead();

        $actionUrl = $record->data['action_url'] ?? null;

        if (filled($actionUrl)) {
            return redirect()->to($actionUrl);
        }

        return redirect()->route('notifications.index');
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $record = $this->notificationForUser($request, $notification);
        $record->markAsRead();

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    private function notificationForUser(Request $request, string $notification): DatabaseNotification
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $request->user()->getMorphClass())
            ->where('notifiable_id', $request->user()->getKey())
            ->whereKey($notification)
            ->firstOrFail();
    }
}
