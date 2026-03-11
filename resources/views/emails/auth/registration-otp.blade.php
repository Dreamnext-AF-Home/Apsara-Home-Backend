<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verification Code - AF Home</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;">

          <!-- Header -->
          <tr>
            <td style="background:#111827;padding:20px 28px;border-radius:14px 14px 0 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="font-size:18px;font-weight:800;color:#f97316;letter-spacing:-0.5px;">
                    AF Home
                  </td>
                  <td align="right" style="font-size:11px;color:#9ca3af;letter-spacing:0.5px;">
                    Premium Furniture and Appliances
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Orange Banner -->
          <tr>
            <td style="background:linear-gradient(135deg,#ea580c 0%,#f97316 60%,#fb923c 100%);padding:36px 28px 28px;text-align:center;">
              <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 16px;">
                <tr>
                  <td style="background:rgba(255,255,255,0.2);border-radius:50%;width:60px;height:60px;text-align:center;vertical-align:middle;">
                    <span style="font-size:28px;line-height:60px;display:block;">&#9993;</span>
                  </td>
                </tr>
              </table>
              <h1 style="margin:0 0 6px;font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">
                Verify Your Account
              </h1>
              <p style="margin:0;font-size:14px;color:#ffedd5;">
                Enter the code below to complete your registration.
              </p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="background:#ffffff;padding:36px 28px;">

              <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">
                Hi there, here is your one-time verification code for <strong style="color:#1e293b;">AF Home</strong>. This code expires in <strong>10 minutes</strong>.
              </p>

              <!-- OTP Code Box -->
              <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 28px;">
                <tr>
                  <td style="background:#f8fafc;border:2px solid #fed7aa;border-radius:16px;padding:24px 40px;text-align:center;">
                    <p style="margin:0 0 10px;font-size:11px;font-weight:700;color:#9ca3af;letter-spacing:2px;text-transform:uppercase;">
                      Verification Code
                    </p>
                    <table role="presentation" cellspacing="0" cellpadding="0" align="center">
                      <tr>
                        @for ($i = 0; $i < 4; $i++)
                        <td style="padding:0 8px;text-align:center;vertical-align:bottom;">
                          <p style="margin:0;font-size:42px;font-weight:900;color:#ea580c;font-family:'Courier New',monospace;line-height:1;">
                            {{ $otp[$i] ?? '&nbsp;' }}
                          </p>
                          <div style="height:3px;width:38px;background:#ea580c;border-radius:2px;margin-top:10px;"></div>
                        </td>
                        @endfor
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 12px;font-size:13px;color:#64748b;line-height:1.6;">
                If you did not create an account with AF Home, you can safely ignore this email.
              </p>

              <p style="margin:0;font-size:13px;color:#94a3b8;">
                For your security, never share this code with anyone. AF Home will never ask for your OTP.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 28px;border-radius:0 0 14px 14px;text-align:center;">
              <p style="margin:0;font-size:11px;color:#94a3b8;">
                &copy; {{ date('Y') }} AF Home. All rights reserved.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
