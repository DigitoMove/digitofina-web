<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SystemSettingController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', SystemSetting::class);

        $settings = SystemSetting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group');

        return view('settings.index', compact('settings'));
    }

    public function update(UpdateSystemSettingsRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', SystemSetting::class);

        foreach ($request->validated('settings', []) as $key => $value) {
            $setting = SystemSetting::where('key', $key)->firstOrFail();
            $old = $setting->toArray();
            $setting->update([
                'value' => $value,
                'updated_by' => $request->user()->id,
            ]);
            $auditLogger->record('setting.updated', $setting, $setting->fresh()->toArray(), $old);
        }

        return redirect()->route('settings.index')->with('status', 'Settings updated.');
    }
}
