<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

class TotpController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        $customer = $request->user();
        $google2fa = new Google2FA();

        $secret = $google2fa->generateSecretKey();

        $customer->c_totp_secret = $secret;
        $customer->save();

        $appName = config('app.name');
        $otpAuthUrl = $google2fa->getQRCodeUrl($appName, $customer->c_email, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCodeBase64 = 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($otpAuthUrl));

        return response()->json([
            'qr_code_url' => $qrCodeBase64,
            'secret' => $secret,
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $customer = $request->user();

        if (empty($customer->c_totp_secret)) {
            return response()->json(['message' => 'TOTP setup not initiated. Call /totp/setup first.'], 422);
        }

        $google2fa = new Google2FA();
        $google2fa->setWindow(1);

        if (! $google2fa->verifyKey($customer->c_totp_secret, $request->input('code'))) {
            return response()->json(['message' => 'Invalid code. Please try again.'], 422);
        }

        $customer->c_totp_enabled = true;
        $customer->save();

        return response()->json(['message' => 'Authenticator app enabled.']);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $customer = $request->user();

        if (empty($customer->c_totp_secret)) {
            return response()->json(['message' => 'Authenticator app not configured.'], 422);
        }

        $google2fa = new Google2FA();
        $google2fa->setWindow(1);

        if (! $google2fa->verifyKey($customer->c_totp_secret, $request->input('code'))) {
            return response()->json(['message' => 'Invalid code. Please try again.'], 422);
        }

        $customer->c_totp_enabled = false;
        $customer->c_totp_secret = null;
        $customer->save();

        return response()->json(['message' => 'Authenticator app removed.']);
    }
}
