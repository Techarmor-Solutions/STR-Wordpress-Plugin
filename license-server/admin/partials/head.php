<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f3f5; margin: 0; color: #1a1a2e; }
.container { max-width: 1100px; margin: 0 auto; padding: 32px 20px; }
nav { background: #1a1a2e; color: #fff; padding: 0 20px; display: flex; align-items: center; gap: 24px; height: 52px; }
nav a { color: #cdd9f0; text-decoration: none; font-size: 14px; font-weight: 500; }
nav a:hover, nav a.active { color: #fff; }
nav .brand { font-weight: 700; font-size: 16px; color: #fff; margin-right: 16px; }
nav .logout { margin-left: auto; }
h1 { font-size: 24px; margin: 0 0 20px; }
h2 { font-size: 18px; margin: 0 0 12px; }
.btn { display: inline-block; padding: 8px 16px; border-radius: 5px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; }
.btn-primary { background: #2271b1; color: #fff; }
.btn-primary:hover { background: #135e96; }
.btn-danger { background: #d63638; color: #fff; }
.btn-danger:hover { background: #a71d2a; }
.btn-restore { background: #00a32a; color: #fff; }
.btn-restore:hover { background: #007020; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.data-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.data-table th { background: #f8f9fa; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #555; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.data-table td { padding: 10px 14px; border-bottom: 1px solid #f0f2f5; font-size: 14px; vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: #fafbfc; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
.badge-active  { background: #e6f4ea; color: #137333; }
.badge-revoked { background: #fce8e8; color: #a71d2a; }
.badge-expired { background: #f0f0f0; color: #555; }
.stats { display: flex; gap: 16px; flex-wrap: wrap; }
.stat-card { background: #fff; border-radius: 8px; padding: 20px 24px; min-width: 140px; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-left: 4px solid #2271b1; }
.stat-green { border-color: #00a32a; }
.stat-red   { border-color: #d63638; }
.stat-gray  { border-color: #999; }
.stat-value { font-size: 32px; font-weight: 700; line-height: 1; }
.stat-label { font-size: 13px; color: #666; margin-top: 4px; }
.alert { padding: 12px 16px; border-radius: 5px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #e6f4ea; color: #137333; }
.alert-error   { background: #fce8e8; color: #a71d2a; }
.form-card { background: #fff; border-radius: 8px; padding: 28px; box-shadow: 0 1px 4px rgba(0,0,0,.08); max-width: 540px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
.form-group textarea { min-height: 80px; resize: vertical; }
.form-group .hint { font-size: 12px; color: #777; margin-top: 3px; }
.key-display { font-family: monospace; font-size: 16px; background: #f1f3f5; padding: 12px 16px; border-radius: 5px; border: 1px solid #ddd; user-select: all; letter-spacing: 1px; }
.search-bar { display: flex; gap: 8px; margin-bottom: 16px; }
.search-bar input { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
</style>
