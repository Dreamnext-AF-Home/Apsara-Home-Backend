<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $portalLabel }} Sign-in Approval</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;">
          <tr>
            <td style="background:#111827;padding:20px 28px;border-radius:14px 14px 0 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="font-size:18px;font-weight:800;color:#f97316;">AF Home</td>
                  <td align="right" style="font-size:11px;color:#9ca3af;letter-spacing:0.5px;">{{ $portalLabel }}</td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:linear-gradient(135deg,#0369a1 0%,#0ea5e9 60%,#38bdf8 100%);padding:34px 28px 24px;text-align:center;">
              <h1 style="margin:0 0 6px;font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">
                New Device Sign-in
              </h1>
              <p style="margin:0;font-size:14px;color:#e0f2fe;">
                Was this you? Approve or deny this login attempt.
              </p>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:30px 28px;">
              <p style="margin:0 0 18px;font-size:14px;color:#475569;line-height:1.6;">
                We detected a new sign-in attempt for <strong>{{ $email }}</strong>.
              </p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border:1px solid #e2e8f0;border-radius:12px;">
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#334155;"><strong>Device:</strong> {{ $device }} ({{ $platform }} / {{ $browser }})</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#334155;border-top:1px solid #e2e8f0;"><strong>Location:</strong> {{ $location }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#334155;border-top:1px solid #e2e8f0;"><strong>IP:</strong> {{ $ipAddress !== '' ? $ipAddress : 'Unknown' }}</td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 16px;">
                <tr>
                  <td style="padding:0 0 10px;">
                    <a href="{{ $approveUrl }}" style="display:block;text-align:center;background:#16a34a;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 16px;border-radius:10px;">Yes, it is me</a>
                  </td>
                </tr>
                <tr>
                  <td>
                    <a href="{{ $denyUrl }}" style="display:block;text-align:center;background:#dc2626;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 16px;border-radius:10px;">No, it is not me</a>
                  </td>
                </tr>
              </table>

              <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">
                This request expires in <strong>{{ $expiresInMinutes }} minutes</strong>. If you did not request this, deny it and change your password immediately.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
