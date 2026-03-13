<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Supplier Password</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">
    <div style="max-width:640px;margin:0 auto;padding:32px 16px;">
        <div style="overflow:hidden;border:1px solid #e2e8f0;border-radius:24px;background:#ffffff;box-shadow:0 12px 30px rgba(15,23,42,0.08);">
            <div style="padding:32px;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#ecfeff,#ffffff 55%,#f0fdfa);">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.2em;text-transform:uppercase;color:#0f766e;">Supplier Portal</p>
                <h1 style="margin:0;font-size:28px;line-height:1.2;color:#0f172a;">Reset your password</h1>
                <p style="margin:16px 0 0;font-size:15px;line-height:1.7;color:#475569;">
                    Hello {{ $name }}, we received a request to reset the password for your AF Home supplier portal access under
                    <strong>{{ $supplierName }}</strong>.
                </p>
            </div>

            <div style="padding:32px;">
                <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#475569;">
                    Use the button below to choose a new password. This reset link will expire on <strong>{{ $expiresAt }}</strong>.
                </p>

                <p style="margin:0 0 28px;">
                    <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 22px;border-radius:16px;background:#0891b2;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">
                        Reset Supplier Password
                    </a>
                </p>

                <p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#64748b;">
                    If the button does not work, copy and paste this link into your browser:
                </p>
                <p style="margin:0;font-size:13px;line-height:1.8;word-break:break-all;color:#0f766e;">
                    <a href="{{ $resetUrl }}" style="color:#0f766e;text-decoration:underline;">{{ $resetUrl }}</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
