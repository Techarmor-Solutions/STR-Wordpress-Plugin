<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Your Stay is Coming Up</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
.container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.header { background: #16213e; color: #fff; padding: 32px 40px; }
.body { padding: 40px; }
.info-box { background: #f0f7ff; border-left: 4px solid #2563eb; padding: 16px 20px; border-radius: 0 6px 6px 0; margin: 24px 0; }
.footer { background: #f8f9fa; padding: 24px 40px; font-size: 12px; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1 style="margin: 0; font-size: 24px;">Your Stay is Coming Up!</h1>
    <p style="margin: 8px 0 0; opacity: 0.8;">3 days until check-in</p>
  </div>
  <div class="body">
    <p>Hi {guest_name},</p>
    <p>We're excited to have you at <strong>{property_name}</strong>! Your check-in is on <strong>{check_in_date}</strong>.</p>

    <div class="info-box">
      <strong>Quick details:</strong><br>
      Check-in: {check_in_date} after {check_in_time}<br>
      Check-out: {check_out_date} by {check_out_time}<br>
      Address: {address}
    </div>

    <p>You'll receive your door code and detailed check-in instructions on the morning of your arrival. Stay tuned!</p>
    <p>If you have any questions before then, you can reach your host at <strong>{host_phone}</strong>.</p>
    <p>See you soon!</p>
  </div>
  <div class="footer">
    <p>This email was sent to {guest_email}.</p>
  </div>
</div>
</body>
</html>
