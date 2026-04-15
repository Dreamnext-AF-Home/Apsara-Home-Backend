<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $portalLabel }} Login Code</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;">
          <tr>
            <td style="background:#111827;padding:20px 28px;border-radius:14px 14px 0 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="font-size:18px;font-weight:800;color:#f97316;">
                    AF Home
                  </td>
                  <td align="right" style="font-size:11px;color:#9ca3af;letter-spacing:0.5px;">
                    {{ $portalLabel }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:linear-gradient(135deg,#0369a1 0%,#0ea5e9 60%,#38bdf8 100%);padding:36px 28px 28px;text-align:center;">
              <h1 style="margin:0 0 6px;font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">
                Verify Your Login
              </h1>
              <p style="margin:0;font-size:14px;color:#e0f2fe;">
                Enter this one-time code to continue signing in.
              </p>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:36px 28px;">
              <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">
                We received a login attempt for <strong>{{ $portalLabel }}</strong> using <strong>{{ $email }}</strong>.
                Use the code below. It expires in <strong>{{ $expiresInMinutes }} minutes</strong>.
              </p>

              <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 28px;">
                <tr>
                  <td style="background:#f8fafc;border:2px solid #bae6fd;border-radius:16px;padding:24px 36px;text-align:center;">
                    <p style="margin:0;font-size:36px;font-weight:900;color:#0369a1;font-family:'Courier New',monospace;letter-spacing:6px;">
                      {{ $otp }}
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0;font-size:13px;color:#94a3b8;">
                If this was not you, change your password immediately and contact support.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
