<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Check-In Instructions</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
.container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.header { background: #065f46; color: #fff; padding: 32px 40px; }
.body { padding: 40px; }
.code-box { background: #1a1a2e; color: #4ade80; font-family: 'Courier New', monospace; font-size: 28px; font-weight: bold; text-align: center; padding: 24px; border-radius: 8px; letter-spacing: 6px; margin: 24px 0; }
.info-grid { display: grid; gap: 16px; margin: 24px 0; }
.info-item { background: #f8f9fa; padding: 16px; border-radius: 6px; }
.info-item strong { display: block; font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 4px; }
.footer { background: #f8f9fa; padding: 24px 40px; font-size: 12px; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1 style="margin: 0; font-size: 24px;">You're Checking In Today!</h1>
    <p style="margin: 8px 0 0; opacity: 0.8;">Welcome to {property_name}</p>
  </div>
  <div class="body">
    <p>Hi {guest_name},</p>
    <p>Today's the day! Here's everything you need to get settled in at <strong>{property_name}</strong>.</p>

    <p><strong>Your door code:</strong></p>
    <div class="code-box">{door_code}</div>

    <div class="info-grid">
      <div class="info-item">
        <strong>Address</strong>
        {address}
      </div>
      <div class="info-item">
        <strong>Check-in Time</strong>
        {check_in_time}
      </div>
      <div class="info-item">
        <strong>WiFi Network &amp; Password</strong>
        {wifi_password}
      </div>
      <div class="info-item">
        <strong>Host Contact</strong>
        {host_phone}
      </div>
    </div>

    <p>Check-out is on <strong>{check_out_date} at {check_out_time}</strong>. Please make sure all doors are locked and keys/codes are secured before you leave.</p>
    <p>Enjoy your stay!</p>
  </div>
  <div class="footer">
    <p>This email was sent to {guest_email}. Need help? Contact your host at {host_phone}.</p>
  </div>
</div>
</body>
</html>
