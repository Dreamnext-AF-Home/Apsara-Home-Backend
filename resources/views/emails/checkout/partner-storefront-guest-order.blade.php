<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>New Guest Order Notification</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#0f172a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
          <tr>
            <td style="background:#0f172a;padding:24px 28px;">
              <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#93c5fd;">Partner Storefront Order</p>
              <h1 style="margin:0;font-size:24px;font-weight:800;color:#ffffff;">
                New guest order for {{ $payload['storefront_display_name'] ?? 'Partner Storefront' }}
              </h1>
            </td>
          </tr>

          <tr>
            <td style="padding:24px 28px 8px;">
              <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#475569;">
                A guest customer completed a paid checkout through your partner storefront. Here are the customer and order details.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding:0 28px 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <tr>
                  <td colspan="2" style="background:#f8fafc;padding:10px 16px;border-bottom:1px solid #e2e8f0;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Customer Information</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;width:38%;">Name</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;font-weight:600;">{{ $payload['customer_name'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Email</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:13px;color:#0f172a;">{{ $payload['customer_email'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Phone</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;">{{ $payload['customer_phone'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;background:#fafafa;font-size:12px;color:#94a3b8;">Shipping Address</td>
                  <td style="padding:12px 16px;background:#fafafa;font-size:13px;color:#0f172a;">{{ $payload['shipping_address'] ?? '-' }}</td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:0 28px 20px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <tr>
                  <td colspan="2" style="background:#f8fafc;padding:10px 16px;border-bottom:1px solid #e2e8f0;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Order Summary</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;width:38%;">Storefront</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;font-weight:600;">{{ $payload['storefront_display_name'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Checkout ID</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#475569;font-family:monospace;">{{ $payload['checkout_id'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Reference No.</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#475569;font-family:monospace;">{{ $payload['payment_intent_id'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Product</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:13px;color:#0f172a;">{{ data_get($payload, 'order.product_name', '-') }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">SKU</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#475569;font-family:monospace;">{{ data_get($payload, 'order.product_sku', '-') }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Quantity</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:13px;color:#0f172a;">{{ data_get($payload, 'order.quantity', 1) }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Payment Method</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;text-transform:capitalize;">{{ $payload['payment_method'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Referred By</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:13px;color:#0f172a;">{{ $payload['referred_by'] ?? '-' }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Voucher</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;">
                    {{ data_get($payload, 'voucher.code') ?: '-' }}
                    @if ((float) data_get($payload, 'voucher.discount', 0) > 0)
                      (&#8369;{{ number_format((float) data_get($payload, 'voucher.discount', 0), 2) }} discount)
                    @endif
                  </td>
                </tr>
                <tr>
                  <td style="padding:12px 16px;background:#eff6ff;font-size:12px;color:#2563eb;font-weight:700;">Amount Paid</td>
                  <td style="padding:12px 16px;background:#eff6ff;font-size:18px;color:#1d4ed8;font-weight:800;">
                    &#8369;{{ number_format((float) ($payload['amount'] ?? 0), 2) }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:0 28px 28px;">
              <p style="margin:0;font-size:12px;color:#94a3b8;">
                This is an automated storefront notification generated by AF Home checkout.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
