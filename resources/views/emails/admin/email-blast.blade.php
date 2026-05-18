<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
@php
  $renderedBody = (string) ($body ?? '');
  $renderedBody = preg_replace_callback(
    '/<img\b[^>]*\bsrc=["\']data:(image\/[a-zA-Z0-9.+-]+);base64,([^"\']+)["\'][^>]*>/i',
    function ($matches) use ($message) {
      $mime = strtolower(trim((string) ($matches[1] ?? 'image/png')));
      $payload = preg_replace('/\s+/', '', (string) ($matches[2] ?? ''));
      $binary = base64_decode($payload, true);
      if ($binary === false) {
        return $matches[0];
      }

      $extension = match ($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'png',
      };

      $cid = $message->embedData($binary, 'inline-' . uniqid('', true) . '.' . $extension, $mime);
      return preg_replace('/\bsrc=["\'][^"\']+["\']/i', 'src="' . $cid . '"', $matches[0], 1) ?: $matches[0];
    },
    $renderedBody
  );
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;">
        <tr>
          <td style="padding:0;">
            @if ($banner_image_base64)
<div style="text-align:center;margin:0;">
  <img src="data:image/jpeg;base64,{{ $banner_image_base64 }}" alt="Banner" style="display:block;width:100%;max-width:100%;height:auto;border:0;">
</div>
            @endif
            <div style="padding:22px 22px 24px;font-size:20px;line-height:1.65;color:#111827;">
              {!! $renderedBody !!}
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
