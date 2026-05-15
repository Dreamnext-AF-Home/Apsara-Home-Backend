<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
@if ($banner_image_base64)
<div style="text-align:center;margin:0 0 20px;">
  <img src="data:image/jpeg;base64,{{ $banner_image_base64 }}" alt="Banner" style="max-width:100%;height:auto;max-height:200px;">
</div>
@endif
{!! $body !!}
</body>
</html>
