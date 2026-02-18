<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Check-Out Reminder</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
.container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.header { background: #7c3aed; color: #fff; padding: 32px 40px; }
.body { padding: 40px; }
.checklist { background: #f8f9fa; padding: 24px; border-radius: 8px; margin: 24px 0; }
.checklist li { margin-bottom: 8px; }
.footer { background: #f8f9fa; padding: 24px 40px; font-size: 12px; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1 style="margin: 0; font-size: 24px;">Check-Out Tomorrow</h1>
    <p style="margin: 8px 0 0; opacity: 0.8;">We hope you had a great stay!</p>
  </div>
  <div class="body">
    <p>Hi {guest_name},</p>
    <p>Just a friendly reminder that check-out at <strong>{property_name}</strong> is tomorrow, <strong>{check_out_date} at {check_out_time}</strong>.</p>

    <div class="checklist">
      <strong>Check-out checklist:</strong>
      <ul>
        <li>Lock all doors and windows</li>
        <li>Leave keys in the designated spot</li>
        <li>Turn off lights, AC/heat</li>
        <li>Take all personal belongings</li>
        <li>Place used towels in the bathtub</li>
        <li>Take out trash if needed</li>
      </ul>
    </div>

    <p>Thank you so much for staying with us. We'd love to have you back!</p>
    <p>If you have any issues during check-out, contact your host at <strong>{host_phone}</strong>.</p>
  </div>
  <div class="footer">
    <p>This email was sent to {guest_email}.</p>
  </div>
</div>
</body>
</html>
