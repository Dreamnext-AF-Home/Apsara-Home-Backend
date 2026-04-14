<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminSettingsController extends Controller
{
    public function showGeneral(): JsonResponse
    {
        $settings = SystemSetting::query()->first();

        return response()->json([
            'settings' => $this->formatSettings($settings),
        ]);
    }

    public function publicGeneral(): JsonResponse
    {
        $settings = SystemSetting::query()->first();

        return response()->json([
            'settings' => $this->formatSettings($settings),
        ]);
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'system_name' => 'nullable|string|max:150',
            'company_name' => 'nullable|string|max:150',
            'support_email' => 'nullable|email|max:150',
            'contact_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'branches' => 'nullable|string|max:2000',
            'timezone' => 'nullable|string|max:80',
            'currency' => 'nullable|string|max:20',
            'date_format' => 'nullable|string|max:40',
            'language' => 'nullable|string|max:40',
            'logo' => 'nullable|image|max:5120',
            'favicon' => 'nullable|image|max:2048',
        ]);

        $settings = SystemSetting::query()->first();

        if (!$settings) {
            $settings = new SystemSetting();
        }

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $settings->logo_path = $request->file('logo')->store('settings/logo', 'public');
        }

        if ($request->hasFile('favicon')) {
            if ($settings->favicon_path) {
                Storage::disk('public')->delete($settings->favicon_path);
            }
            $settings->favicon_path = $request->file('favicon')->store('settings/favicon', 'public');
        }

        foreach ([
            'system_name',
            'company_name',
            'support_email',
            'contact_number',
            'address',
            'branches',
            'timezone',
            'currency',
            'date_format',
            'language',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $settings->{$field} = $validated[$field];
            }
        }

        $settings->save();

        return response()->json([
            'message' => 'Settings saved successfully.',
            'settings' => $this->formatSettings($settings),
        ]);
    }

    private function formatSettings(?SystemSetting $settings): array
    {
        return [
            'system_name' => $settings?->system_name ?? 'Apsara Home',
            'company_name' => $settings?->company_name ?? '',
            'support_email' => $settings?->support_email ?? '',
            'contact_number' => $settings?->contact_number ?? '',
            'address' => $settings?->address ?? '',
            'branches' => $settings?->branches ?? '',
            'logo_url' => $settings?->logo_path ? Storage::disk('public')->url($settings->logo_path) : null,
            'favicon_url' => $settings?->favicon_path ? Storage::disk('public')->url($settings->favicon_path) : null,
            'timezone' => $settings?->timezone ?? 'Asia/Manila',
            'currency' => $settings?->currency ?? 'PHP',
            'date_format' => $settings?->date_format ?? 'MM/DD/YYYY',
            'language' => $settings?->language ?? 'English',
            'updated_at' => optional($settings?->updated_at)->toDateTimeString(),
        ];
    }
}
