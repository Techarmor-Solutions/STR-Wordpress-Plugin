<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Confirmed</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
.container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.header { background: #1a1a2e; color: #fff; padding: 32px 40px; }
.header h1 { margin: 0; font-size: 24px; font-weight: 600; }
.body { padding: 40px; }
.detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee; }
.detail-row:last-child { border-bottom: none; }
.label { color: #666; font-size: 14px; }
.value { font-weight: 600; text-align: right; }
.total-row { background: #f8f9fa; padding: 16px; border-radius: 6px; margin-top: 24px; }
.footer { background: #f8f9fa; padding: 24px 40px; font-size: 12px; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Booking Confirmed!</h1>
    <p style="margin: 8px 0 0; opacity: 0.8;">Confirmation #<?php echo isset( $data['id'] ) ? esc_html( $data['id'] ) : ''; ?></p>
  </div>
  <div class="body">
    <p>Hi {guest_name},</p>
    <p>Great news — your booking at <strong>{property_name}</strong> is confirmed. We can't wait to host you!</p>

    <div class="detail-row">
      <span class="label">Property</span>
      <span class="value">{property_name}</span>
    </div>
    <div class="detail-row">
      <span class="label">Check-in</span>
      <span class="value">{check_in_date} at {check_in_time}</span>
    </div>
    <div class="detail-row">
      <span class="label">Check-out</span>
      <span class="value">{check_out_date} at {check_out_time}</span>
    </div>
    <div class="detail-row">
      <span class="label">Nights</span>
      <span class="value">{nights}</span>
    </div>
    <div class="detail-row">
      <span class="label">Address</span>
      <span class="value">{address}</span>
    </div>
    <div class="total-row">
      <div class="detail-row" style="border: none; padding: 0;">
        <span class="label" style="font-size: 16px; color: #333;">Total Charged</span>
        <span class="value" style="font-size: 18px;">${total}</span>
      </div>
    </div>

    <p style="margin-top: 32px;">You'll receive check-in instructions 3 days before your arrival. If you have questions before then, contact your host at {host_phone}.</p>
  </div>
  <div class="footer">
    <p>This email was sent to {guest_email}. If you have questions, reply to this email.</p>
  </div>
</div>
</body>
</html>
