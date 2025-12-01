// Safari Compatible Service Worker
const CACHE_VERSION = 'laracash-safari-v1';
const CACHE_NAME = 'laracash-safari-cache';

// Упрощенный список файлов для кеширования (Safari более строгий)
const CACHE_URLS = [
    '/css/app.css',
    '/js/app.js',
    '/vendor/fontawesome-free/css/all.min.css',
    '/vendor/adminlte/dist/css/adminlte.min.css',
    '/vendor/jquery/jquery.min.js',
    '/vendor/bootstrap/js/bootstrap.min.js',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/favicon.png'
];

// Установка Service Worker - Safari Compatible
self.addEventListener('install', function(event) {
    console.log('🚀 SW: Installing Safari version:', CACHE_VERSION);

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                console.log('📦 SW: Caching core files');
                return cache.addAll(CACHE_URLS);
            })
            .then(function() {
                console.log('✅ SW: All files cached');
            })
            .catch(function(error) {
                console.error('❌ SW: Cache error:', error);
            })
    );
});

// Активация - Safari Compatible
self.addEventListener('activate', function(event) {
    console.log('🔄 SW: Activating Safari version:', CACHE_VERSION);

    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.map(function(cacheName) {
                    if (cacheName !== CACHE_NAME) {
                        console.log('🗑️ SW: Delete old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
        .then(function() {
            console.log('✅ SW: Activation complete');
        })
    );
});

// Упрощенная обработка fetch - Safari Compatible
self.addEventListener('fetch', function(event) {
    // Safari требует чтобы мы всегда что-то возвращали
    if (!event.request.url.startsWith('http')) {
        // Пропускаем chrome-extension и другие протоколы
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then(function(response) {
                // Если есть в кеше - возвращаем из кеша
                if (response) {
                    console.log('📦 SW: From cache:', event.request.url);
                    return response;
                }

                // Если нет в кеше - пробуем сеть
                return fetch(event.request)
                    .then(function(response) {
                        // Кешируем только успешные GET запросы
                        if (response.ok && event.request.method === 'GET') {
                            // Кешируем только основные файлы
                            if (isCacheableFile(event.request.url)) {
                                const responseClone = response.clone();
                                caches.open(CACHE_NAME)
                                    .then(function(cache) {
                                        cache.put(event.request, responseClone);
                                    })
                                    .catch(function(error) {
                                        console.log('SW: Cache put error:', error);
                                    });
                            }
                        }
                        return response;
                    })
                    .catch(function(error) {
                        console.log('SW: Network error:', error);

                        // Для HTML запросов отдаем офлайн страницу
                        if (event.request.headers.get('accept').includes('text/html')) {
                            return new Response(getOfflineHTML(), {
                                status: 200,
                                statusText: 'OK',
                                headers: {
                                    'Content-Type': 'text/html'
                                }
                            });
                        }

                        // Для остальных запросов - простая ошибка
                        return new Response('Offline - no cached version', {
                            status: 503,
                            statusText: 'Service Unavailable'
                        });
                    });
            })
            .catch(function(error) {
                console.error('SW: Cache match error:', error);

                // Если даже кеш не работает - пробуем сеть
                return fetch(event.request)
                    .catch(function() {
                        return new Response('Service Unavailable', {
                            status: 503,
                            statusText: 'Service Unavailable'
                        });
                    });
            })
    );
});

// Проверяем можно ли кешировать файл
function isCacheableFile(url) {
    // Базовые файлы приложения
    const fileExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.ico', '.woff', '.woff2'];
    const hasCacheableExtension = fileExtensions.some(ext => url.includes(ext));

    // или содержит эти пути
    const cacheablePaths = ['/css/', '/js/', '/icons/', '/vendor/'];
    const hasCacheablePath = cacheablePaths.some(path => url.includes(path));

    return hasCacheableExtension || hasCacheablePath;
}

// Упрощенная офлайн страница для Safari
function getOfflineHTML() {
    return '<!DOCTYPE html>' +
        '<html lang="ru">' +
        '<head>' +
        '<meta charset="UTF-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
        '<title>Твой кешбэк - Офлайн</title>' +
        '<style>' +
        'body{' +
        'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
        'background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);' +
        'color:#fff;margin:0;padding:20px;' +
        'display:flex;justify-content:center;align-items:center;' +
        'min-height:100vh;text-align:center' +
        '}' +
        '.offline-container{' +
        'background:rgba(255,255,255,.1);' +
        'backdrop-filter:blur(10px);' +
        'border-radius:20px;padding:40px;max-width:400px' +
        '}' +
        '.offline-icon{font-size:64px;margin-bottom:20px}' +
        '.offline-title{font-size:24px;margin-bottom:10px}' +
        '.offline-text{font-size:16px;opacity:.8;line-height:1.5}' +
        '.retry-btn{' +
        'background:#fff;color:#667eea;border:none;' +
        'padding:12px 24px;border-radius:8px;font-size:16px;' +
        'cursor:pointer;margin-top:20px' +
        '}' +
        '</style>' +
        '</head>' +
        '<body>' +
        '<div class="offline-container">' +
        '<div class="offline-icon">📦</div>' +
        '<h1 class="offline-title">Офлайн режим</h1>' +
        '<p class="offline-text">' +
        'Приложение работает офлайн.<br>' +
        'Доступны сохраненные данные.<br>' +
        'Проверьте подключение к интернету.' +
        '</p>' +
        '<button class="retry-btn" onclick="location.reload()">🔄 Обновить</button>' +
        '</div>' +
        '</body>' +
        '</html>';
}

console.log('🚀 Safari Service Worker initialized:', CACHE_VERSION);