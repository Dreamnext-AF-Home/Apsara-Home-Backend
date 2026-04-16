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
            'branches' => 'nullable|string|max:8000',
            'timezone' => 'nullable|string|max:80',
            'currency' => 'nullable|string|max:20',
            'date_format' => 'nullable|string|max:40',
            'language' => 'nullable|string|max:40',
            'enable_test_payments' => 'nullable|boolean',
            'enable_manual_checkout_mode' => 'nullable|boolean',
            'logo' => 'nullable|image|max:5120',
            'favicon' => 'nullable|image|max:2048',
            'website_qr_code' => 'nullable|image|max:5120',
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

        if ($request->hasFile('website_qr_code')) {
            if ($settings->website_qr_code_path) {
                Storage::disk('public')->delete($settings->website_qr_code_path);
            }
            $settings->website_qr_code_path = $request->file('website_qr_code')->store('settings/website-qr-code', 'public');
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
            'enable_test_payments',
            'enable_manual_checkout_mode',
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

    public function showSecurity(): JsonResponse
    {
        $settings = SystemSetting::query()->first();

        return response()->json([
            'settings' => $this->formatSecuritySettings($settings),
        ]);
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_timeout_minutes' => 'required|integer|min:5|max:1440',
            'max_login_attempts' => 'required|integer|min:1|max:20',
            'password_min_length' => 'required|integer|min:6|max:64',
            'enable_2fa' => 'required|boolean',
        ]);

        $settings = SystemSetting::query()->first();

        if (!$settings) {
            $settings = new SystemSetting();
        }

        $settings->session_timeout_minutes = $validated['session_timeout_minutes'];
        $settings->max_login_attempts = $validated['max_login_attempts'];
        $settings->password_min_length = $validated['password_min_length'];
        $settings->enable_2fa = $validated['enable_2fa'];
        $settings->save();

        return response()->json([
            'message' => 'Security settings saved successfully.',
            'settings' => $this->formatSecuritySettings($settings),
        ]);
    }

    public function showNotifications(): JsonResponse
    {
        $settings = SystemSetting::query()->first();

        return response()->json([
            'settings' => $this->formatNotificationSettings($settings),
        ]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_notifications' => 'required|boolean',
            'sms_notifications' => 'required|boolean',
            'admin_alerts' => 'required|boolean',
        ]);

        $settings = SystemSetting::query()->first();

        if (!$settings) {
            $settings = new SystemSetting();
        }

        $settings->email_notifications = $validated['email_notifications'];
        $settings->sms_notifications = $validated['sms_notifications'];
        $settings->admin_alerts = $validated['admin_alerts'];
        $settings->save();

        return response()->json([
            'message' => 'Notification settings saved successfully.',
            'settings' => $this->formatNotificationSettings($settings),
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
            'website_qr_code_url' => $settings?->website_qr_code_path ? Storage::disk('public')->url($settings->website_qr_code_path) : null,
            'timezone' => $settings?->timezone ?? 'Asia/Manila',
            'currency' => $settings?->currency ?? 'PHP',
            'date_format' => $settings?->date_format ?? 'MM/DD/YYYY',
            'language' => $settings?->language ?? 'English',
            'enable_test_payments' => (bool)($settings?->enable_test_payments ?? false),
            'enable_manual_checkout_mode' => (bool)($settings?->enable_manual_checkout_mode ?? false),
            'updated_at' => optional($settings?->updated_at)->toDateTimeString(),
        ];
    }

    private function formatSecuritySettings(?SystemSetting $settings): array
    {
        return [
            'session_timeout_minutes' => $settings?->session_timeout_minutes ?? 60,
            'max_login_attempts' => $settings?->max_login_attempts ?? 5,
            'password_min_length' => $settings?->password_min_length ?? 8,
            'enable_2fa' => (bool)($settings?->enable_2fa ?? false),
            'updated_at' => optional($settings?->updated_at)->toDateTimeString(),
        ];
    }

    private function formatNotificationSettings(?SystemSetting $settings): array
    {
        return [
            'email_notifications' => (bool)($settings?->email_notifications ?? true),
            'sms_notifications' => (bool)($settings?->sms_notifications ?? false),
            'admin_alerts' => (bool)($settings?->admin_alerts ?? true),
            'updated_at' => optional($settings?->updated_at)->toDateTimeString(),
        ];
    }
}
