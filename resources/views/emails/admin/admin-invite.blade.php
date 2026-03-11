<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AF Home Admin Invite</title>
</head>
<body style="margin:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:20px;padding:32px;">
            <div style="display:inline-block;padding:8px 14px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">
                Admin Invitation
            </div>

            <h1 style="margin:20px 0 8px;font-size:28px;line-height:1.2;">Set up your AF Home admin account</h1>
            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#475569;">
                Hello {{ $name }},
            </p>
            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#475569;">
                You were invited to join the AF Home admin portal as <strong>{{ $roleLabel }}</strong>.
                Click the button below to verify this email and set your password.
            </p>

            <div style="margin:28px 0;">
                <a href="{{ $setupUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 22px;border-radius:12px;">
                    Verify Email and Set Password
                </a>
            </div>

            <div style="margin:0 0 18px;padding:16px 18px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;">
                <p style="margin:0 0 8px;font-size:13px;color:#64748b;">Invited email</p>
                <p style="margin:0;font-size:15px;font-weight:700;color:#0f172a;">{{ $email }}</p>
                <p style="margin:14px 0 8px;font-size:13px;color:#64748b;">Invite expires</p>
                <p style="margin:0;font-size:15px;font-weight:700;color:#0f172a;">{{ $expiresAt }}</p>
            </div>

            <p style="margin:0 0 10px;font-size:13px;line-height:1.7;color:#64748b;">
                If the button does not work, copy and paste this link into your browser:
            </p>
            <p style="margin:0 0 20px;word-break:break-all;font-size:13px;line-height:1.7;color:#2563eb;">
                {{ $setupUrl }}
            </p>

            <p style="margin:0;font-size:13px;line-height:1.7;color:#64748b;">
                If you were not expecting this invitation, you can safely ignore this email.
            </p>
        </div>
    </div>
</body>
</html>
