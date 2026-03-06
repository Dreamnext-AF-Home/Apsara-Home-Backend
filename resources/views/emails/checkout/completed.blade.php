<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Purchase Order Confirmed - AF Home</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;">
          <tr>
            <td style="background:#111827;padding:20px 28px;border-radius:14px 14px 0 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <img
                      src="{{ $message->embed(public_path('Image/af_home_logo.png')) }}"
                      alt="AF Home"
                      width="120"
                      height="auto"
                      style="display:block;height:auto;border:0;max-width:120px;"
                    />
                  </td>
                  <td align="right" style="font-size:11px;color:#9ca3af;letter-spacing:0.5px;">
                    Premium Furniture and Appliances
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:linear-gradient(135deg,#ea580c 0%,#f97316 60%,#fb923c 100%);padding:36px 28px 30px;text-align:center;">
              <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 18px;">
                <tr>
                  <td style="background:rgba(255,255,255,0.2);border-radius:50%;width:64px;height:64px;text-align:center;vertical-align:middle;">
                    <span style="font-size:32px;line-height:64px;display:block;">&#10003;</span>
                  </td>
                </tr>
              </table>
              <h1 style="margin:0 0 6px;font-size:26px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">
                Purchase Order Confirmed
              </h1>
              <p style="margin:0;font-size:14px;color:#ffedd5;font-weight:400;">
                Your payment has been received. Thank you for shopping with AF Home.
              </p>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:28px 28px 8px;">
              <p style="margin:0 0 6px;font-size:15px;color:#1e293b;">
                Hi <strong>{{ $payload['customer_name'] ?? 'Valued Customer' }}</strong>,
              </p>
              <p style="margin:0 0 24px;font-size:14px;color:#64748b;line-height:1.6;">
                Your purchase order is now in our processing queue. Here are the order details:
              </p>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:0 28px 28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <tr>
                  <td colspan="2" style="background:#f8fafc;padding:10px 16px;border-bottom:1px solid #e2e8f0;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Purchase Order Details</span>
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;width:42%;white-space:nowrap;">Description</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#1e293b;font-weight:500;">
                    {{ $payload['description'] ?? '-' }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Product</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:13px;color:#1e293b;font-weight:500;">
                    {{ data_get($payload, 'order.product_name', '-') }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Quantity</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#1e293b;font-weight:500;">
                    {{ data_get($payload, 'order.quantity', 1) }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">SKU</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#475569;font-family:monospace;">
                    {{ data_get($payload, 'order.product_sku', '-') }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Checkout ID</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#475569;font-family:monospace;">
                    {{ $payload['checkout_id'] ?? '-' }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Reference No.</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#475569;font-family:monospace;">
                    {{ $payload['payment_intent_id'] ?? '-' }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#94a3b8;">Payment Method</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#1e293b;text-transform:capitalize;">
                    {{ $payload['payment_method'] ?? '-' }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#94a3b8;">Shipping Address</td>
                  <td style="padding:12px 16px;border-bottom:1px solid #f1f5f9;background:#fafafa;font-size:12px;color:#475569;">
                    {{ $payload['shipping_address'] ?? '-' }}
                  </td>
                </tr>

                <tr>
                  <td style="padding:12px 16px;font-size:12px;color:#94a3b8;">Status</td>
                  <td style="padding:12px 16px;font-size:13px;">
                    <span style="display:inline-block;background:#dcfce7;color:#16a34a;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:0.5px;">
                      &#10003;&nbsp;{{ strtoupper($payload['status'] ?? 'PAID') }}
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:0 28px 28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                style="background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;border-radius:12px;">
                <tr>
                  <td style="padding:18px 20px;">
                    <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#c2410c;text-transform:uppercase;letter-spacing:1px;">Amount Paid</p>
                    <p style="margin:0;font-size:28px;font-weight:800;color:#ea580c;">
                      &#8369;&nbsp;{{ number_format((float) ($payload['amount'] ?? 0), 2) }}
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:0 28px 32px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                style="background:#f8fafc;border-radius:10px;padding:16px 18px;border-left:4px solid #f97316;">
                <tr>
                  <td>
                    <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#ea580c;text-transform:uppercase;letter-spacing:0.5px;">What happens next?</p>
                    <p style="margin:0;font-size:13px;color:#475569;line-height:1.6;">
                      Our team will review and process your order. You will receive another email once your item is ready for delivery.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:0 28px;">
              <hr style="border:none;border-top:1px solid #f1f5f9;margin:0;" />
            </td>
          </tr>

          <tr>
            <td style="background:#ffffff;padding:20px 28px;border-radius:0 0 14px 14px;text-align:center;">
              <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#111827;">AF Home</p>
              <p style="margin:0 0 12px;font-size:12px;color:#94a3b8;">Premium Furniture and Appliances</p>
              <p style="margin:0;font-size:11px;color:#cbd5e1;">
                This is an automated email. Please do not reply directly to this message.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
