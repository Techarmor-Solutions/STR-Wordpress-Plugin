<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>How Was Your Stay?</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
.container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.header { background: #d97706; color: #fff; padding: 32px 40px; }
.body { padding: 40px; text-align: center; }
.stars { font-size: 48px; margin: 24px 0; }
.footer { background: #f8f9fa; padding: 24px 40px; font-size: 12px; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1 style="margin: 0; font-size: 24px;">How Was Your Stay?</h1>
    <p style="margin: 8px 0 0; opacity: 0.8;">{property_name}</p>
  </div>
  <div class="body">
    <p>Hi {guest_name},</p>
    <p>We hope you had a wonderful time at <strong>{property_name}</strong> from {check_in_date} to {check_out_date}.</p>

    <div class="stars">⭐⭐⭐⭐⭐</div>

    <p>Your feedback means the world to us and helps other travelers discover great places to stay. If you have a moment, we'd love to hear about your experience!</p>

    <p style="color: #666; font-size: 14px;">You can also reach us directly at {host_phone} if you have any feedback to share privately.</p>

    <p>We hope to see you again soon!</p>
  </div>
  <div class="footer">
    <p>This email was sent to {guest_email}. You're receiving this because you recently stayed at {property_name}.</p>
  </div>
</div>
</body>
</html>
