<?php
// admin/partials/footer_stub.php
if (session_status() === PHP_SESSION_NONE) session_start();
?>
  </div> <!-- .container-fluid p-4 -->
</div> <!-- .main -->

<!-- Optional: place small page-specific scripts here -->
<script>
  // small accessibility helper (optional)
  // Example: announce sidebar state changes to screen readers if you add a live region
</script>


<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
