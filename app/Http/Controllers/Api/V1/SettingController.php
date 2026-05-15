<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateApiSystemSettingsRequest;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use App\Services\FinancialControlService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        ApiSecurity::assertPermission(request()->user(), 'manage-settings');

        return ApiResponse::collection(
            SystemSettingResource::collection(ApiSecurity::scopeTenant(SystemSetting::query(), request()->user())->orderBy('group')->orderBy('key')->get()),
            'System settings retrieved.'
        );
    }

    public function update(UpdateApiSystemSettingsRequest $request, AuditLogger $auditLogger, FinancialControlService $controls): JsonResponse
    {
        if ($controls->requiresMakerChecker($request->user(), 'settings_update')) {
            $oldValues = ApiSecurity::scopeTenant(SystemSetting::query(), $request->user())
                ->whereIn('key', array_keys($request->validated('settings', [])))
                ->pluck('value', 'key')
                ->all();

            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'settings_update',
                subject: null,
                payload: ['settings' => $request->validated('settings', [])],
                amount: null,
                requiredPermission: 'manage-settings',
                reason: 'System settings change requires checker approval.',
                oldValues: $oldValues,
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Settings update submitted for maker-checker review.', 202);
        }

        $updated = collect();

        foreach ($request->validated('settings', []) as $key => $value) {
            $setting = ApiSecurity::scopeTenant(SystemSetting::query(), $request->user())->where('key', $key)->firstOrFail();
            $old = $setting->toArray();

            $setting->update([
                'value' => $value,
                'updated_by' => $request->user()->id,
            ]);

            $auditLogger->record('setting.updated_api', $setting, [
                'key' => $setting->key,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], $old);

            $updated->push($setting->fresh());
        }

        return ApiResponse::collection(SystemSettingResource::collection($updated), 'System settings updated.');
    }
}
