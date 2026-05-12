<?php

namespace App\Services;

use App\Models\DrawerSession;
use App\Models\TellerDrawer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TellerService
{
    public function openDrawer(TellerDrawer $drawer, User $user, float $openingBalance): DrawerSession
    {
        if ($openingBalance < 0) {
            throw new InvalidArgumentException('Opening balance cannot be negative.');
        }

        return DB::transaction(function () use ($drawer, $user, $openingBalance): DrawerSession {
            $drawer = TellerDrawer::query()->whereKey($drawer->id)->lockForUpdate()->firstOrFail();

            if ($drawer->status === 'open') {
                throw new InvalidArgumentException('Drawer is already open.');
            }

            $drawer->update([
                'current_balance' => $openingBalance,
                'status' => 'open',
            ]);

            return DrawerSession::create([
                'teller_drawer_id' => $drawer->id,
                'user_id' => $user->id,
                'opening_balance' => $openingBalance,
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });
    }
}
