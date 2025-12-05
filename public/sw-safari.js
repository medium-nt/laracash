// Safari Compatible Service Worker - Fixed Version
const CACHE_VERSION = 'laracash-safari-v5-search-only';
const CACHE_NAME = 'laracash-safari-cache';

// URL которые никогда не должны кешироваться
const NEVER_CACHE_PATTERNS = [
    '/livewire/',
    '/api/',
    '/storage/card_cashback_image/'
];

// Основные файлы для кеширования (только статические ресурсы)
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

// Установка Service Worker
self.addEventListener('install', function(event) {
    console.log('🚀 Safari SW: Installing version:', CACHE_VERSION);

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                console.log('📦 Safari SW: Caching core files');
                return cache.addAll(CACHE_URLS);
            })
            .then(function() {
                console.log('✅ Safari SW: All files cached');
            })
            .catch(function(error) {
                console.error('❌ Safari SW: Cache error:', error);
            })
    );
});

// Активация
self.addEventListener('activate', function(event) {
    console.log('🔄 Safari SW: Activating version:', CACHE_VERSION);

    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.map(function(cacheName) {
                    if (cacheName !== CACHE_NAME) {
                        console.log('🗑️ Safari SW: Delete old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
        .then(function() {
            console.log('✅ Safari SW: Activation complete');
        })
    );
});

// Основная обработка fetch
self.addEventListener('fetch', function(event) {
    var request = event.request;
    var url = request.url;

    // Пропускаем не-HTTP запросы
    if (!url.startsWith('http')) {
        return;
    }

    event.respondWith(
        handleRequest(request)
    );
});

// Проверяем является ли запрос к странице поиска
function isSearchPage(url) {
    return url.indexOf('/search/') !== -1 || url.match(/\/search\/[a-zA-Z0-9]+/);
}

// Функция обработки запросов
function handleRequest(request) {
    var refererUrl = request.referrer;

    // Проверяем относится ли запрос к контексту страницы поиска
    if (!isSearchPageContext(request.url, refererUrl)) {
        // Пропускаем не-поисковые запросы - не обрабатываем их сервис-воркером
        return fetch(request).catch(function(error) {
            return new Response('Service Unavailable', {
                status: 503,
                statusText: 'Service Unavailable',
                headers: {
                    'Content-Type': 'text/plain'
                }
            });
        });
    }

    // Для URL которые никогда не кешируются - только сеть
    if (shouldNeverCache(request.url)) {
        console.log('🌐 Safari SW: Network only:', request.url);
        return fetch(request).catch(function(error) {
            // Safari fallback для сетевых запросов без кеширования
            console.log('❌ Safari SW: Network failed for non-cacheable request:', request.url);
            return new Response('Service Unavailable', {
                status: 503,
                statusText: 'Service Unavailable',
                headers: {
                    'Content-Type': 'text/plain'
                }
            });
        });
    }

    // Для страниц поиска - Network First (всегда свежие данные)
    if (isSearchPage(request.url)) {
        console.log('🔍 Safari SW: Search page Network First:', request.url);
        return networkFirst(request);
    }

    // Для HTML страниц - Network First
    if (isHTMLPage(request.url)) {
        return networkFirst(request);
    }

    // Для статических файлов - Cache First
    if (isStaticFile(request.url)) {
        return cacheFirst(request);
    }

    // Для всего остального - Cache First
    return cacheFirst(request);
}

// Cache First для Safari
function cacheFirst(request) {
    return caches.match(request)
        .then(function(cachedResponse) {
            // Если есть в кеше - возвращаем из кеша
            if (cachedResponse) {
                console.log('📦 Safari SW: From cache:', request.url);
                return cachedResponse;
            }

            // Если нет в кеше - пробуем сеть
            return fetch(request)
                .then(function(networkResponse) {
                    console.log('🌐 Safari SW: From network:', request.url);

                    // Кешируем успешные GET запросы
                    if (networkResponse.ok && request.method === 'GET') {
                        var responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME)
                            .then(function(cache) {
                                cache.put(request, responseClone);
                            })
                            .catch(function(error) {
                                console.log('Safari SW: Cache put error:', error);
                            });
                    }
                    return networkResponse;
                })
                .catch(function(error) {
                    console.log('Safari SW: Network failed:', request.url);

                    // Для HTML запросов - пробуем найти PWA страницу
                    if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
                        return getOfflinePWAPage();
                    }

                    // Возвращаем базовый ответ, не null
                    return new Response('Service Unavailable', {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: {
                            'Content-Type': 'text/plain'
                        }
                    });
                });
        });
}

// Network First для Safari с обязательным кешированием
function networkFirst(request) {
    return fetch(request)
        .then(function(networkResponse) {
            console.log('🌐 Safari SW: HTML from network:', request.url);

            // ВСЕГДА кешируем успешные HTML ответы для оффлайн режима
            if (networkResponse.ok && request.method === 'GET') {
                var responseClone = networkResponse.clone();
                caches.open(CACHE_NAME)
                    .then(function(cache) {
                        cache.put(request, responseClone);
                        console.log('✅ Safari SW: Cached successful response:', request.url);
                    })
                    .catch(function(error) {
                        console.log('Safari SW: Cache put error:', error);
                    });
            }
            return networkResponse;
        })
        .catch(function(error) {
            console.log('📦 Safari SW: Network failed, trying cache:', request.url);

            // Пробуем достать из кеша
            return caches.match(request)
                .then(function(cachedResponse) {
                    if (cachedResponse) {
                        console.log('✅ Safari SW: HTML from cache fallback:', request.url);
                        return cachedResponse;
                    }

                    console.log('❌ Safari SW: No cache available for:', request.url);

                    // Если нет в кеше и нет сети - возвращаем оффлайн страницу
                    if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
                        return getOfflinePWAPage();
                    }

                    // Для остальных запросов - возвращаем базовый ответ, не null
                    return new Response('Offline - no cached version available', {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: {
                            'Content-Type': 'text/plain'
                        }
                    });
                })
                .catch(function(cacheError) {
                    console.log('❌ Safari SW: Cache match error:', cacheError);

                    // Финальный fallback - всегда возвращаем валидный Response
                    if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
                        return getOfflinePWAPage();
                    }

                    return new Response('Service Unavailable', {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: {
                            'Content-Type': 'text/plain'
                        }
                    });
                });
        });
}

// Попытка получить офлайн PWA страницу
function getOfflinePWAPage() {
    console.log('🔄 Safari SW: Getting offline PWA page');

    // Ищем в кеше любые HTML страницы
    return caches.open(CACHE_NAME)
        .then(function(cache) {
            return cache.keys()
                .then(function(requests) {
                    console.log('📄 Safari SW: Cached requests:', requests.map(function(r) { return r.url; }));

                    // Ищем любые HTML или PWA страницы
                    var htmlRequests = requests.filter(function(req) {
                        return req.url.includes('/search/') ||
                               req.url.includes('.html') ||
                               req.url.match(/\/search\/[a-zA-Z0-9]+/);
                    });

                    if (htmlRequests.length > 0) {
                        console.log('✅ Safari SW: Found PWA pages:', htmlRequests.map(function(r) { return r.url; }));
                        // Возвращаем первую найденную PWA страницу
                        return cache.match(htmlRequests[0]);
                    }

                    console.log('❌ Safari SW: No PWA pages found in cache');
                    return null; // Это нормально, обработается в следующем then()
                });
        })
        .then(function(pwaPage) {
            if (pwaPage) {
                return pwaPage;
            }

            // Если нет PWA страниц - создаем офлайн индикатор
            console.log('📦 Safari SW: Returning offline indicator');
            return new Response(getOfflineHTML(), {
                status: 200,
                statusText: 'OK',
                headers: {
                    'Content-Type': 'text/html'
                }
            });
        })
        .catch(function(error) {
            console.error('Safari SW: Cache open error:', error);

            // В случае ошибки - возвращаем офлайн индикатор
            return new Response(getOfflineHTML(), {
                status: 200,
                statusText: 'OK',
                headers: {
                    'Content-Type': 'text/html'
                }
            });
        });
}

// Проверяем нужно ли никогда не кешировать URL
function shouldNeverCache(url) {
    return NEVER_CACHE_PATTERNS.some(function(pattern) {
        return url.indexOf(pattern) !== -1;
    });
}

// Проверяем является ли запрос к HTML странице
function isHTMLPage(url) {
    return url.indexOf('.html') !== -1 ||
           url.endsWith('/') ||
           (!url.includes('.') && url.indexOf('/vendor/') === -1 && url.indexOf('/icons/') === -1);
}

// Проверяем является ли запрос к статическим файлам
function isStaticFile(url) {
    // Базовые файлы приложения
    var fileExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.ico', '.woff', '.woff2'];
    var hasCacheableExtension = fileExtensions.some(function(ext) {
        return url.indexOf(ext) !== -1;
    });

    // или содержит эти пути
    var cacheablePaths = ['/css/', '/js/', '/icons/', '/vendor/'];
    var hasCacheablePath = cacheablePaths.some(function(path) {
        return url.indexOf(path) !== -1;
    });

    return hasCacheableExtension || hasCacheablePath;
}

// Проверяем относится ли запрос к контексту страницы поиска (Safari версия)
function isSearchPageContext(requestUrl, refererUrl) {
    var url;

    try {
        url = new URL(requestUrl);
    } catch (e) {
        return false;
    }

    // 1. Если сам URL относится к поисковой странице
    if (url.pathname === '/search' || url.pathname.indexOf('/search/') === 0) {
        return true;
    }

    // 2. Если есть реферер и он с поисковой страницы
    if (refererUrl) {
        try {
            var referer = new URL(refererUrl);
            if (referer.pathname === '/search' || referer.pathname.indexOf('/search/') === 0) {
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

// HTML для офлайн индикатора
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
        'PWA работает офлайн.<br>' +
        'Проверьте подключение к интернету.<br>' +
        'Данные доступны в основном приложении.' +
        '</p>' +
        '<button class="retry-btn" onclick="location.reload()">🔄 Обновить</button>' +
        '</div>' +
        '</body>' +
        '</html>';
}

console.log('🚀 Safari Service Worker initialized:', CACHE_VERSION);