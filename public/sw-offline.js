const CACHE_VERSION = 'laracash-v5-search-only';
const CACHE_NAME = 'laracash-cache';

// URL которые никогда не должны кешироваться
const NEVER_CACHE_PATTERNS = [
    '/livewire/',
    '/api/',
    '/storage/card_cashback_image/'
];

// Основные файлы которые нужно кешировать для PWA (только статические ресурсы)
const CACHE_URLS = [
    // CSS файлы
    '/vendor/fontawesome-free/css/all.min.css',
    '/vendor/adminlte/dist/css/adminlte.min.css',
    '/css/app.css',

    // JavaScript файлы
    '/js/app.js',
    '/vendor/jquery/jquery.min.js',
    '/vendor/bootstrap/js/bootstrap.min.js',

    // Иконки и manifest
    '/icons/icon-57x57.png',
    '/icons/icon-60x60.png',
    '/icons/icon-72x72.png',
    '/icons/icon-76x76.png',
    '/icons/icon-114x114.png',
    '/icons/icon-120x120.png',
    '/icons/icon-144x144.png',
    '/icons/icon-152x152.png',
    '/icons/icon-167x167.png',
    '/icons/icon-180x180.png',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/favicon.png'
];

// Установка Service Worker - кешируем все основные файлы
self.addEventListener('install', event => {
    console.log('🚀 SW: Installing version:', CACHE_VERSION);

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('📦 SW: Caching core files');
                return cache.addAll(CACHE_URLS);
            })
            .then(() => {
                console.log('✅ SW: All core files cached successfully');
                // Пропускаем ожидание и активируем сразу
                return self.skipWaiting();
            })
            .catch(error => {
                console.error('❌ SW: Error caching files:', error);
            })
    );
});

// Активация - очистка старого кеша
self.addEventListener('activate', event => {
    console.log('🔄 SW: Activating version:', CACHE_VERSION);

    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('🗑️ SW: Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
        .then(() => {
            console.log('✅ SW: Activation complete');
            // Берем контроль над всеми вкладками
            return self.clients.claim();
        })
    );
});

// Основная логика перехвата запросов
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);

    // Пропускаем Chrome Extension запросы
    if (url.protocol === 'chrome-extension:') {
        return;
    }

    // Пропускаем не-HTTP запросы
    if (!url.protocol.startsWith('http')) {
        return;
    }

    // Стратегия для разных типов запросов
    event.respondWith(handleRequest(request));
});

// Основная функция обработки запросов
async function handleRequest(request) {
    const url = new URL(request.url);
    const refererUrl = request.referrer;

    try {
        // 0. Проверяем относится ли запрос к контексту страницы поиска
        if (!isSearchPageContext(request.url, refererUrl)) {
            // Пропускаем не-поисковые запросы - не обрабатываем их сервис-воркером
            return await fetch(request);
        }

        // 1. Для URL которые никогда не кешируются - только сеть
        if (shouldNeverCache(request.url)) {
            return await networkOnly(request);
        }

        // 2. Для страниц поиска - Network First (всегда свежие данные)
        if (isSearchPage(request.url)) {
            return await networkFirst(request);
        }

        // 3. Для HTML страниц в контексте поиска - Network First
        if (isHTMLPage(request.url)) {
            return await networkFirst(request);
        }

        // 4. Для статических файлов - Cache First
        if (isStaticFile(request.url)) {
            return await cacheFirst(request);
        }

        // 5. Для всего остального - Cache First с fallback
        return await cacheFirst(request);

    } catch (error) {
        console.error('❌ SW: Error handling request:', request.url, error);

        // Если все не удалось - пробуем отдать из кеша
        try {
            return await caches.match(request);
        } catch (cacheError) {
            console.error('❌ SW: Cache also failed:', cacheError);

            // Для HTML запросов отдаем оффлайн страницу
            if (request.headers.get('accept')?.includes('text/html')) {
                return new Response(getOfflineHTML(), {
                    headers: { 'Content-Type': 'text/html' }
                });
            }

            // Для остальных - просто ошибка
            return new Response('Offline - no cached version available', {
                status: 503,
                statusText: 'Service Unavailable'
            });
        }
    }
}

// Cache First стратегия - сначала кеш, потом сеть
async function cacheFirst(request) {
    // Сначала пробуем кеш
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
        console.log('📦 SW: Serving from cache:', request.url);
        // В фоне обновляем кеш
        updateCache(request);
        return cachedResponse;
    }

    // Если в кеше нет - пробуем сеть
    try {
        console.log('🌐 SW: Fetching from network:', request.url);
        const networkResponse = await fetch(request);

        // Кешируем успешные ответы
        if (networkResponse.ok && request.method === 'GET') {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => {
                cache.put(request, responseClone);
            });
        }

        return networkResponse;

    } catch (error) {
        console.error('❌ SW: Network failed for:', request.url, error);
        throw error;
    }
}

// Network Only стратегия - только сеть, без кеширования
async function networkOnly(request) {
    console.log('🌐 SW: Network only request:', request.url);
    const networkResponse = await fetch(request);
    return networkResponse;
}

// Network First стратегия - сначала сеть, потом кеш
async function networkFirst(request) {
    try {
        console.log('🌐 SW: HTML/Network First request:', request.url);
        const networkResponse = await fetch(request);

        // Кешируем успешные ответы для оффлайн режима
        if (networkResponse.ok && request.method === 'GET') {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => {
                cache.put(request, responseClone);
            });
        }

        return networkResponse;

    } catch (error) {
        console.log('📦 SW: Network failed, trying cache for:', request.url);

        // Пробуем достать из кеша
        const cachedResponse = await caches.match(request);

        if (cachedResponse) {
            console.log('✅ SW: Serving from cache fallback:', request.url);
            return cachedResponse;
        }

        throw error;
    }
}

// Обновление кеша в фоне (не блокируя основной запрос)
async function updateCache(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response);
        }
    } catch (error) {
        // Игнорируем ошибки фонового обновления
        console.log('ℹ️ SW: Background update failed:', error);
    }
}

// Проверяем нужно ли никогда не кешировать URL
function shouldNeverCache(url) {
    return NEVER_CACHE_PATTERNS.some(pattern => url.includes(pattern));
}

// Проверяем является ли запрос к HTML странице
function isHTMLPage(url) {
    return url.includes('.html') ||
           url.endsWith('/') ||
           (!url.includes('.') && !url.includes('/vendor/') && !url.includes('/icons/'));
}

// Проверяем является ли запрос к странице поиска
function isSearchPage(url) {
    return url.includes('/search/') || url.match(/\/search\/[a-zA-Z0-9]+/);
}

// Проверяем является ли запрос к статическим файлам
function isStaticFile(url) {
    return CACHE_URLS.some(cacheUrl => url.includes(cacheUrl)) ||
           url.includes('/vendor/') ||
           url.includes('/css/') ||
           url.includes('/js/') ||
           url.includes('/icons/') ||
           url.endsWith('.css') ||
           url.endsWith('.js') ||
           url.endsWith('.png') ||
           url.endsWith('.jpg') ||
           url.endsWith('.jpeg') ||
           url.endsWith('.svg') ||
           url.endsWith('.ico') ||
           url.endsWith('.woff') ||
           url.endsWith('.woff2');
}

// Проверяем относится ли запрос к контексту страницы поиска
function isSearchPageContext(requestUrl, refererUrl) {
    const url = new URL(requestUrl);

    // 1. Если сам URL относится к поисковой странице
    if (url.pathname === '/search' || url.pathname.startsWith('/search/')) {
        return true;
    }

    // 2. Если есть реферер и он с поисковой страницы
    if (refererUrl) {
        try {
            const referer = new URL(refererUrl);
            if (referer.pathname === '/search' || referer.pathname.startsWith('/search/')) {
                return true;
            }
        } catch (e) {
            // Игнорируем невалидный реферер
        }
    }

    // 3. Если это статический ресурс, который может быть запрошен со страницы поиска
    if (isStaticFile(requestUrl)) {
        return true;
    }

    return false;
}

// HTML для оффлайн страницы
function getOfflineHTML() {
    return `
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Твой кешбэк - Офлайн режим</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                margin: 0;
                padding: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                text-align: center;
            }
            .offline-container {
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                border-radius: 20px;
                padding: 40px;
                max-width: 400px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            }
            .offline-icon {
                font-size: 64px;
                margin-bottom: 20px;
            }
            .offline-title {
                font-size: 24px;
                margin-bottom: 10px;
            }
            .offline-text {
                font-size: 16px;
                opacity: 0.8;
                line-height: 1.5;
            }
            .retry-btn {
                background: white;
                color: #667eea;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
                margin-top: 20px;
                transition: transform 0.2s;
            }
            .retry-btn:hover {
                transform: scale(1.05);
            }
        </style>
    </head>
    <body>
        <div class="offline-container">
            <div class="offline-icon">📦</div>
            <h1 class="offline-title">Офлайн режим</h1>
            <p class="offline-text">
                Приложение работает в офлайн режиме.<br>
                Доступны сохраненные данные и кешбэки.<br>
                Для обновления проверьте подключение к интернету.
            </p>
            <button class="retry-btn" onclick="location.reload()">
                🔄 Обновить
            </button>
        </div>
    </body>
    </html>
    `;
}

// Обработка сообщений от клиента (для обновления кеша)
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data && event.data.type === 'CACHE_UPDATE') {
        console.log('🔄 SW: Manual cache update requested');
        // Можно добавить логику принудительного обновления кеша
    }
});

console.log('🚀 Service Worker initialized:', CACHE_VERSION);
