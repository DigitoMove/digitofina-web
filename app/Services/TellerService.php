<?php

namespace App\Services;

use App\Models\DrawerSession;
use App\Models\TellerDrawer;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TellerService
{
    public function openDrawer(TellerDrawer $drawer, User $teller, float $openingBalance, ?User $openedBy = null): DrawerSession
    {
        if ($openingBalance < 0) {
            throw new InvalidArgumentException('Opening balance cannot be negative.');
        }

        if ($openingBalance > 0) {
            CurrencyDenomination::assertValid($openingBalance, 'Opening balance');
        }

        return DB::transaction(function () use ($drawer, $teller, $openingBalance, $openedBy): DrawerSession {
            $drawer = TellerDrawer::query()->whereKey($drawer->id)->lockForUpdate()->firstOrFail();

            if ($drawer->status === 'open') {
                throw new InvalidArgumentException('Drawer is already open.');
            }

            if ($drawer->assigned_user_id && (int) $drawer->assigned_user_id !== (int) $teller->id && ! $openedBy?->canPerform('manage-users')) {
                throw new InvalidArgumentException('This drawer is assigned to another teller.');
            }

            $hasOpenSession = DrawerSession::query()
                ->where('user_id', $teller->id)
                ->where('status', 'open')
                ->exists();

            if ($hasOpenSession) {
                throw new InvalidArgumentException('This teller already has an open drawer session.');
            }

            $drawer->update([
                'assigned_user_id' => $teller->id,
                'current_balance' => $openingBalance,
                'status' => 'open',
            ]);

            return DrawerSession::create([
                'teller_drawer_id' => $drawer->id,
                'user_id' => $teller->id,
                'opening_balance' => $openingBalance,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });
    }
}
