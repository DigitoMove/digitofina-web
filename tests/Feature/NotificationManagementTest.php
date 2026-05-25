<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function notificationUser(): User
{
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);

    return User::factory()->create([
        'role_id' => $role->id,
        'status' => 'active',
        'is_active' => true,
    ]);
}

it('shows a users notifications and can mark one as read', function () {
    $user = notificationUser();
    $user->notify(new AccountActivityNotification(
        'Loan ready',
        'A loan is ready for disbursement.',
        route('dashboard'),
        'loan',
        'warning',
    ));

    $notification = $user->notifications()->firstOrFail();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertSuccessful()
        ->assertSee('Loan ready')
        ->assertSee('A loan is ready for disbursement.')
        ->assertSee('1 unread');

    $this->actingAs($user)
        ->post(route('notifications.read', $notification))
        ->assertRedirect();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks a notification as read when it is opened', function () {
    $user = notificationUser();
    $user->notify(new AccountActivityNotification(
        'Client updated',
        'Client details were changed.',
        route('dashboard'),
        'client',
        'info',
    ));

    $notification = $user->notifications()->firstOrFail();

    $this->actingAs($user)
        ->get(route('notifications.open', $notification))
        ->assertRedirect(route('dashboard'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks all unread notifications as read', function () {
    $user = notificationUser();
    $user->notify(new AccountActivityNotification('One', 'First message.'));
    $user->notify(new AccountActivityNotification('Two', 'Second message.'));

    expect($user->unreadNotifications()->count())->toBe(2);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect()
        ->assertSessionHas('status', 'All notifications marked as read.');

    expect($user->unreadNotifications()->count())->toBe(0);
});
