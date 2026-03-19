const CACHE_VERSION = 'v1.0.0';
const CACHE_NAME = `ers-cache-${CACHE_VERSION}`;
const PRECACHE_URLS = [
  '/foase_exam_report_system/',
  '/foase_exam_report_system/index.php',
  '/foase_exam_report_system/offline.html',
  '/foase_exam_report_system/assets/bootstrap/css/bootstrap.min.css',
  '/foase_exam_report_system/assets/css/style.css',
  '/foase_exam_report_system/assets/js/main.js',
  '/foase_exam_report_system/assets/bootstrap/js/bootstrap.bundle.min.js'
];

const RUNTIME_IMAGE_CACHE = 'ers-images';
const RUNTIME_API_CACHE = 'ers-api';

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(PRECACHE_URLS))
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.map(key => {
        if (key !== CACHE_NAME && !key.startsWith('ers-')) return caches.delete(key);
        if (key !== CACHE_NAME) return caches.delete(key);
      }))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;

  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).then(response => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
        return response;
      }).catch(() => caches.match('/foase_exam_report_system/index.php')
        .then(res => res || caches.match('/foase_exam_report_system/offline.html')))
    );
    return;
  }

  if (url.pathname.startsWith('/foase_exam_report_system/api/') || url.search.includes('ajax')) {
    event.respondWith(
      fetch(request).then(response => {
        const copy = response.clone();
        caches.open(RUNTIME_API_CACHE).then(cache => cache.put(request, copy));
        return response;
      }).catch(() => caches.match(request))
    );
    return;
  }

  if (request.destination === 'image' || url.pathname.match(/\.(png|jpg|jpeg|gif|webp|svg)$/)) {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request).then(response => {
        const copy = response.clone();
        caches.open(RUNTIME_IMAGE_CACHE).then(cache => cache.put(request, copy));
        return response;
      })))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cached => cached || fetch(request))
  );
});

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});