<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\BusinessCalendarService;
use App\Services\DemoAccessService;
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
    public function index(ModuleService $modules, TenantContext $tenant, DemoAccessService $demoAccess): View
    {
        Gate::authorize('viewAny', SystemSetting::class);

        $user = auth()->user();
        $selectedOrganization = $tenant->organization()
            ?: ($user?->organization_id ? $user->organization : null);

        if ($selectedOrganization) {
            SystemSetting::ensureForOrganization($selectedOrganization);
        } else {
            SystemSetting::ensurePlatformDefaults();
            $demoAccess->ensurePlatformSetting();
        }

        $enabledModules = $user?->hasRole('super-admin') || ! $selectedOrganization
            ? null
            : $modules->enabledSlugs($selectedOrganization);
        $isSuperAdminGlobal = $user?->hasRole('super-admin') && ! $tenant->isScoped();

        $settings = ($selectedOrganization
            ? SystemSetting::forOrganization($selectedOrganization->id)
            : SystemSetting::forPlatform())
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->filter(fn (SystemSetting $setting): bool => $this->settingIsVisible($setting, $enabledModules) && ($setting->key !== 'backup_mail_to' || $isSuperAdminGlobal))
            ->groupBy('group');

        return view('settings.index', [
            'settings' => $settings,
            'selectedOrganization' => $selectedOrganization,
            'organizations' => $user?->hasRole('super-admin') ? Organization::query()->orderBy('name')->get() : collect(),
            'isSuperAdminGlobal' => $isSuperAdminGlobal,
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
            'teller' => in_array('teller', $enabledModules, true),
            default => true,
        };
    }

    public function update(UpdateSystemSettingsRequest $request, AuditLogger $auditLogger, LoanApplicationFeeService $applicationFees, BusinessCalendarService $calendar, DemoAccessService $demoAccess): RedirectResponse
    {
        Gate::authorize('update', SystemSetting::class);
        $organizationId = SystemSetting::resolvedOrganizationId();
        $organizationId === null
            ? SystemSetting::ensurePlatformDefaults()
            : SystemSetting::ensureForOrganization(Organization::query()->findOrFail($organizationId));

        foreach ($request->validated('settings', []) as $key => $value) {
            if ($key === 'backup_mail_to') {
                abort_unless($request->user()->hasRole('super-admin') && ! app(TenantContext::class)->isScoped(), 403);
            }

            $setting = SystemSetting::forOrganization($organizationId)->where('key', $key)->firstOrFail();

            if ($setting->type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

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

                if ($setting->key === 'demo_hidden_hosts') {
                    $demoAccess->assertValidHiddenHosts($value);
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
