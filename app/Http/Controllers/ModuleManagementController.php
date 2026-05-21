<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Organization;
use App\Models\Package;
use App\Services\ModuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ModuleManagementController extends Controller
{
    public function index(Request $request, ModuleService $modules): View
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        $organizations = Organization::query()->with('package')->orderBy('name')->get();
        $organization = $request->integer('organization_id')
            ? Organization::query()->with('package')->findOrFail($request->integer('organization_id'))
            : ($organizations->first() ?: new Organization);

        return view('modules.index', [
            'organizations' => $organizations,
            'organization' => $organization,
            'modules' => $organization->exists ? $modules->modulesForOrganization($organization) : collect(),
            'packages' => Package::query()->where('status', 'active')->with('modules')->orderBy('name')->get(),
            'enabledSlugs' => $organization->exists ? $modules->enabledSlugs($organization) : [],
        ]);
    }

    public function toggle(Request $request, Organization $organization, Module $module, ModuleService $modules): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        $data = $request->validate([
            'action' => ['required', 'in:enable,disable'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'array'],
        ]);

        try {
            if ($data['action'] === 'enable') {
                $modules->enable($organization, $module->load('dependencies'), $request->user(), $data['settings'] ?? null, $data['reason'] ?? null);
            } else {
                $modules->disable($organization, $module->load('dependents'), $request->user(), $data['reason'] ?? null);
            }
        } catch (InvalidArgumentException $exception) {
            return back()->with('warning', $exception->getMessage())->withInput();
        }

        return back()->with('status', 'Module access updated.');
    }

    public function assignPackage(Request $request, Organization $organization, ModuleService $modules): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);

        $data = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
        ]);

        $modules->assignPackage($organization, Package::findOrFail($data['package_id']), $request->user());

        return back()->with('status', 'Package assigned and modules enabled.');
    }

    public function visibility(Request $request, ModuleService $modules): View
    {
        $organization = $request->user()->organization;
        abort_unless($organization, 404);

        return view('modules.visibility', [
            'organization' => $organization,
            'modules' => $modules->modulesForOrganization($organization),
            'enabledSlugs' => $modules->enabledSlugs($organization),
        ]);
    }
}
