FOASE M/A JHS PWA package
========================

Files included:
- manifest.json
- service-worker.js
- offline.html
- pwa-head.php
- pwa-footer.php
- inject_pwa_snippets.php  (one-click injector - backs up files)
- assets/icons/* (your uploaded icons)

Colors:
- Theme Color: #0d47a1
- Background Color: #ffffff

Instructions:
1. Copy the entire contents of this package into your server project root so that the paths match:
   /foase_exam_report_system/manifest.json
   /foase_exam_report_system/service-worker.js
   /foase_exam_report_system/offline.html
   /foase_exam_report_system/pwa-head.php
   /foase_exam_report_system/pwa-footer.php
   /foase_exam_report_system/assets/icons/*

2. If you want the installer to auto-insert includes into your PHP pages, place this package outside and run from your project root:
   php inject_pwa_snippets.php
   This script will search for .php files in /foase_exam_report_system/, create .bak backups and insert include lines
   for pwa-head.php and pwa-footer.php (it will not overwrite backups). Review .bak files if needed.

3. Alternatively, manually add in your main header include (inside <head>):
   <?php include __DIR__.'/pwa-head.php'; ?>

   And before </body> add:
   <?php include __DIR__.'/pwa-footer.php'; ?>

4. Ensure your site is served over HTTPS in production. You can test locally on http://localhost/ without HTTPS.
5. Open the site once online on each device to allow service worker installation. Use DevTools -> Application to debug.

If you want me to run the injector for you (I can prepare a modified list of changed files), tell me and I will prepare a preview of changes.

