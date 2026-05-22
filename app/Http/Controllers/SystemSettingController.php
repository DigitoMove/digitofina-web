<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\BusinessCalendarService;
use App\Services\LoanApplicationFeeService;
use App\Services\ModuleService;
use App\Services\TenantContext;
use App\Support\CurrencyDenomination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SystemSettingController extends Controller
{
    public function index(ModuleService $modules, TenantContext $tenant): View
    {
        Gate::authorize('viewAny', SystemSetting::class);

        $user = auth()->user();
        $selectedOrganization = $tenant->organization()
            ?: ($user?->organization_id ? $user->organization : Organization::query()->oldest('id')->first());

        if ($selectedOrganization) {
            SystemSetting::ensureForOrganization($selectedOrganization);
        }

        $enabledModules = $user?->hasRole('super-admin') || ! $selectedOrganization
            ? null
            : $modules->enabledSlugs($selectedOrganization);

        $settings = $selectedOrganization
            ? SystemSetting::forOrganization($selectedOrganization->id)
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->filter(fn (SystemSetting $setting): bool => $this->settingIsVisible($setting, $enabledModules))
                ->groupBy('group')
            : collect();

        return view('settings.index', [
            'settings' => $settings,
            'selectedOrganization' => $selectedOrganization,
            'organizations' => $user?->hasRole('super-admin') ? Organization::query()->orderBy('name')->get() : collect(),
            'isSuperAdminGlobal' => $user?->hasRole('super-admin') && ! $tenant->isScoped(),
        ]);
    }

    /**
     * @param  array<int, string>|null  $enabledModules
     */
    private function settingIsVisible(SystemSetting $setting, ?array $enabledModules): bool
    {
        if ($enabledModules === null) {
            return true;
        }

        return match ($setting->group) {
            'notifications' => in_array('sms', $enabledModules, true),
            'integrations' => in_array('mobile-money', $enabledModules, true),
            'loans' => in_array('loans', $enabledModules, true),
            default => true,
        };
    }

    public function update(UpdateSystemSettingsRequest $request, AuditLogger $auditLogger, LoanApplicationFeeService $applicationFees, BusinessCalendarService $calendar): RedirectResponse
    {
        Gate::authorize('update', SystemSetting::class);
        $organizationId = SystemSetting::resolvedOrganizationId();

        foreach ($request->validated('settings', []) as $key => $value) {
            $setting = SystemSetting::forOrganization($organizationId)->where('key', $key)->firstOrFail();
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
