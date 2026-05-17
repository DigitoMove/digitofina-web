<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\BusinessCalendarService;
use App\Services\LoanApplicationFeeService;
use App\Support\CurrencyDenomination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    public function update(UpdateSystemSettingsRequest $request, AuditLogger $auditLogger, LoanApplicationFeeService $applicationFees, BusinessCalendarService $calendar): RedirectResponse
    {
        Gate::authorize('update', SystemSetting::class);

        foreach ($request->validated('settings', []) as $key => $value) {
            $setting = SystemSetting::where('key', $key)->firstOrFail();
            try {
                if ($setting->type === 'money' && (float) $value > 0) {
                    CurrencyDenomination::assertValid((float) $value, $setting->label ?? str($key)->headline()->toString());
                }

                if ($setting->type === 'number' && filled($value) && (! is_numeric($value) || (float) $value < 0)) {
                    throw new \InvalidArgumentException(($setting->label ?? str($key)->headline()->toString()).' must be a positive number.');
                }

                if ($setting->key === 'loan_application_fee_ranges') {
                    $applicationFees->assertValidJson($value);
                }

                if ($setting->key === 'repayment_public_holidays') {
                    $calendar->assertValidHolidayJson($value);
                }
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(["settings.{$key}" => $exception->getMessage()]);
            }

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
