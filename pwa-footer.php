<script>
// Service Worker registration
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/foase_exam_report_system/service-worker.js')
    .then(reg => console.log('ServiceWorker registered', reg.scope))
    .catch(err => console.error('SW failed:', err));
  });
}

// Add to Home Screen (A2HS) prompt handling
let deferredPrompt;
const installBtnHtml = `
  <button id="pwa-install-btn" class="btn btn-outline-primary" style="display:none;position:fixed;right:16px;bottom:16px;z-index:9999;border-radius:28px;padding:10px 14px;box-shadow:0 6px 14px rgba(13,71,161,0.15)">
    Install App
  </button>`;
document.addEventListener('DOMContentLoaded', ()=>{
  document.body.insertAdjacentHTML('beforeend', installBtnHtml);
  const installBtn = document.getElementById('pwa-install-btn');
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    installBtn.style.display = 'inline-block';
    installBtn.addEventListener('click', async () => {
      installBtn.style.display = 'none';
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      console.log('User response to install prompt:', outcome);
      deferredPrompt = null;
    });
  });
});
</script>