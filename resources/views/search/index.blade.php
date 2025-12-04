<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Твой кешбек">

    <meta name="mobile-web-app-capable" content="yes">

    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-57x57.png') }}">
    <link rel="apple-touch-icon" sizes="57x57" href="{{ asset('icons/icon-57x57.png') }}">
    <link rel="apple-touch-icon" sizes="60x60" href="{{ asset('icons/icon-60x60.png') }}">
    <link rel="apple-touch-icon" sizes="72x72" href="{{ asset('icons/icon-72x72.png') }}">
    <link rel="apple-touch-icon" sizes="76x76" href="{{ asset('icons/icon-76x76.png') }}">
    <link rel="apple-touch-icon" sizes="114x114" href="{{ asset('icons/icon-114x114.png') }}">
    <link rel="apple-touch-icon" sizes="120x120" href="{{ asset('icons/icon-120x120.png') }}">
    <link rel="apple-touch-icon" sizes="144x144" href="{{ asset('icons/icon-144x144.png') }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ asset('icons/icon-152x152.png') }}">
    <link rel="apple-touch-icon" sizes="167x167" href="{{ asset('icons/icon-167x167.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/icon-180x180.png') }}">

    <!-- PWA Icons -->
    <link rel="icon" sizes="192x192" href="{{ asset('icons/icon-192x192.png') }}">
    <link rel="icon" sizes="512x512" href="{{ asset('icons/icon-512x512.png') }}">

    <!-- PWA Manifest -->
    <link rel="manifest" href="/search/{{ $user->search_token }}/manifest">

    <title>Твой кешбек</title>

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="{{ asset('/vendor/fontawesome-free/css/all.min.css') }}">

    <!-- AdminLTE -->
    <link href="{{ asset('/vendor/adminlte/dist/css/adminlte.min.css') }}" rel="stylesheet">

    <!-- jQuery -->
    <script src="{{ asset('/vendor/jquery/jquery.min.js') }}"></script>

    <!-- Bootstrap -->
    <script src="{{ asset('/vendor/bootstrap/js/bootstrap.min.js') }}"></script>

    <style>
        body {
            background-color: #ffffff;
            color: #007bff;
        }

        .topics-table {
            padding: 18px;
            border: none;
            width: 100%;
            max-width: 800px;
            margin: 1px auto;
        }

        .topics-table tr {
            border-bottom: 1px solid #007bff;
        }

        .topics-table td {
            padding-top: 2px;
            padding-bottom: 5px;
        }

        .search-form {
            max-width: 800px;
            margin: 30px auto;
        }

        .category {
            max-width: 800px;
            margin: 1px auto;
        }

        #search {
            /*border-radius: 5px;*/
        }

        .btn-r {
            border-radius: 0 5px 5px 0 !important;
        }
        .btn-l {
            border-radius: 5px 0 0 5px !important;
        }
    </style>
</head>

<!-- Модальное окно -->
<div class="modal fade" id="modal" tabindex="-1" role="dialog" aria-labelledby="modalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-body">
                <div class="modal-div"></div>
            </div>
        </div>
    </div>
</div>

<body>
    <!-- Page loader для блокирующей загрузки всех данных -->
    <div id="pageLoader" class="page-loader-overlay" style="display: none;">
        <div class="page-loader-content">
            <div class="spinner"></div>
            <h3>Загрузка свежих данных...</h3>
            <div class="loading-stages">
                <div class="stage" id="livewireStage">
                    <span class="stage-icon">⏳</span>
                    <span class="stage-text">Загрузка данных кешбэков</span>
                </div>
                <div class="stage" id="imagesStage" style="display: none;">
                    <span class="stage-icon">🖼️</span>
                    <span class="stage-text">Загрузка скриншотов (<span id="imageProgress">0</span>/<span id="imageTotal">0</span>)</span>
                </div>
                <div class="stage complete" id="completeStage" style="display: none;">
                    <span class="stage-icon">✅</span>
                    <span class="stage-text">Все готово!</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Основной контент скрыт по умолчанию -->
    <div id="mainContent" style="opacity: 0;">
        @livewire('search-component', [
                'user' => $user
        ])
    </div>

<script>
    /**
     * Класс для блокирующей загрузки данных кешбэков
     */
    class DataLoader {
        constructor(token) {
            this.token = token;
            this.freshData = null;
            this.livewireReady = false;
            this.livewireReadyPromise = this.createLivewireReadyPromise();
        }

        createLivewireReadyPromise() {
            return new Promise((resolve) => {
                // Способ 1: Ждем событияLivewire:init
                document.addEventListener('livewire:init', () => {
                    console.log('✅ Livewire событие init получено');
                    this.livewireReady = true;
                    resolve();
                }, { once: true });

                // Способ 2: Ждем загрузки через window.Livewire
                this.waitForLivewireGlobal(resolve);
            });
        }

        async waitForLivewireGlobal(resolve) {
            const maxAttempts = 40; // 40 попыток по 250мс = 10 секунд
            let attempts = 0;

            while (attempts < maxAttempts) {
                if (window.Livewire && window.Livewire.components) {
                    console.log('✅ Livewire глобально доступен');
                    this.livewireReady = true;
                    resolve();
                    return;
                }
                await new Promise(r => setTimeout(r, 250));
                attempts++;
            }
            console.warn('⚠️ Livewire не инициализировался через глобальный объект');
        }

        async loadFreshData() {
            try {
                console.log('🔄 Загрузка свежих данных кешбэков...');

                const response = await fetch(`/api/search-data/${this.token}?v=${Date.now()}`);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.error || 'Unknown error');
                }

                this.freshData = result.data;
                console.log('✅ Свежие данные загружены:', {
                    count: result.count,
                    timestamp: result.timestamp
                });

                // Обновляем Livewire компонент новыми данными
                await this.updateLivewireComponent();

                return true;
            } catch (error) {
                console.error('❌ Ошибка загрузки данных:', error);
                return false;
            }
        }

        async updateLivewireComponent() {
            try {
                console.log('🔄 Обновление Livewire компонента...');

                // Ждем инициализации Livewire
                await this.livewireReadyPromise;

                // Даем дополнительное время на полную инициализацию
                await this.waitForLivewireElements();

                // Ищем Livewire компонент
                const livewireElement = document.querySelector('[wire\\:id]');

                if (!livewireElement) {
                    throw new Error('Livewire компонент не найден');
                }

                // Пробуем получить инстанс через разные методы
                let livewireComponent = null;

                // Метод 1: через Livewire.find()
                try {
                    if (window.Livewire && window.Livewire.find) {
                        livewireComponent = Livewire.find(livewireElement.wireId);
                    }
                } catch (e) {
                    console.log('⚠️ Livewire.find() не сработал:', e.message);
                }

                // Метод 2: через wire:model событие
                if (!livewireComponent) {
                    try {
                        // Ищем компонент через атрибуты
                        const components = document.querySelectorAll('[wire\\:id]');
                        for (const element of components) {
                            if (element.getAttribute('wire:id') === livewireElement.getAttribute('wire:id')) {
                                // Пробуем получить через Livewire.get()
                                if (window.Livewire && window.Livewire.get) {
                                    livewireComponent = Livewire.get(element.getAttribute('wire:id'));
                                }
                                break;
                            }
                        }
                    } catch (e) {
                        console.log('⚠️ Livewire.get() не сработал:', e.message);
                    }
                }

                if (!livewireComponent) {
                    console.error('❌ Не удалось получить Livewire инстанс, пробуем альтернативный метод');
                    await this.alternativeUpdate();
                    return;
                }

                // Обновляем данные компонента
                livewireComponent.set('filteredCategoriesCashback', this.freshData);
                livewireComponent.call('$refresh');

                console.log('✅ Livewire компонент обновлен свежими данными');

            } catch (error) {
                console.error('❌ Ошибка обновления Livewire:', error);
                await this.alternativeUpdate();
            }
        }

        async alternativeUpdate() {
            console.log('🔄 Используем альтернативный метод обновления...');

            // Метод: триггим изменение в поиске для перезагрузки данных
            const searchInput = document.querySelector('input[wire\\:model\\.live]');
            if (searchInput) {
                // Сохраняем текущее значение
                const currentValue = searchInput.value;

                // Очищаем и возвращаем значение для триггера обновления
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));

                setTimeout(() => {
                    searchInput.value = currentValue;
                    searchInput.dispatchEvent(new Event('input', { bubbles: true }));
                }, 100);

                console.log('✅ Альтернативное обновление завершено');
            } else {
                console.warn('⚠️ Не найден поисковый инпут для альтернативного обновления');
            }
        }

        async waitForLivewireElements() {
            console.log('⏳ Ожидание элементов Livewire...');

            const maxAttempts = 20; // 20 попыток по 250мс = 5 секунд
            let attempts = 0;

            while (attempts < maxAttempts) {
                const livewireElement = document.querySelector('[wire\\:id]');
                if (livewireElement && livewireElement.getAttribute('wire:id')) {
                    console.log('✅ Livewire элементы готовы');
                    return;
                }

                await new Promise(resolve => setTimeout(resolve, 250));
                attempts++;
            }

            throw new Error('Livewire элементы не найдены за 5 секунд');
        }

        async waitForLivewireAndLoad() {
            console.log('⏳ Ожидание загрузки Livewire компонента...');

            // Ждем инициализации Livewire
            await this.livewireReadyPromise;

            // Ждем появления элементов в DOM
            await this.waitForLivewireElements();

            console.log('✅ Livewire готов к работе');
            return await this.loadFreshData();
        }
    }

    /**
     * Получить base64 строку изображения из localStorage
     * @param {string} imagePath - путь к изображению относительно /storage/card_cashback_image/
     * @returns {string|null} base64 строка или null если не найдено
     */
    function getCachedImage(imagePath) {
        try {
            const cacheKey = 'cashback_img_' + imagePath;
            return localStorage.getItem(cacheKey);
        } catch (error) {
            console.error('Ошибка при получении изображения из кеша:', error);
            return null;
        }
    }

    /**
     * Сохранить base64 изображение в localStorage
     * @param {string} imagePath - путь к изображению
     * @param {string} base64Data - base64 строка изображения
     */
    function saveImageToCache(imagePath, base64Data) {
        try {
            const cacheKey = 'cashback_img_' + imagePath;
            localStorage.setItem(cacheKey, base64Data);
            console.log('Изображение сохранено в кеш:', imagePath);
        } catch (error) {
            console.error('Ошибка при сохранении изображения в кеш:', error);
        }
    }

    /**
     * Загрузить изображение с сервера и сохранить в кеш
     * @param {string} imagePath - путь к изображению относительно /storage/card_cashback_image/
     * @returns {Promise<string>} Promise который вернет base64 строку
     */
    function loadAndCacheImage(imagePath) {
        return new Promise((resolve, reject) => {
            const timestamp = Date.now();
            const fullUrl = '/storage/card_cashback_image/' + imagePath + '?v=' + timestamp;

            console.log('Загрузка изображения:', fullUrl);

            const img = new Image();
            img.crossOrigin = 'Anonymous';

            img.onload = function() {
                try {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');

                    canvas.width = img.width;
                    canvas.height = img.height;
                    ctx.drawImage(img, 0, 0);

                    const base64 = canvas.toDataURL('image/jpeg', 0.8);

                    saveImageToCache(imagePath, base64);
                    console.log('✅ Изображение успешно загружено и закешировано:', imagePath);
                    resolve(base64);
                } catch (error) {
                    console.error('❌ Ошибка обработки изображения:', imagePath, error);
                    reject(error);
                }
            };

            img.onerror = function() {
                console.error('❌ Ошибка загрузки изображения:', fullUrl);
                reject(new Error('Failed to load image: ' + fullUrl));
            };

            img.onabort = function() {
                console.warn('⚠️ Загрузка изображения отменена:', imagePath);
                reject(new Error('Image loading aborted: ' + imagePath));
            };

            img.src = fullUrl;
        });
    }

    /**
     * Модифицированная функция блокирующей загрузки изображений с интеграцией в loader
     */
    async function blockingLoadImagesWithProgress() {
        console.log('🔄 Начало блокирующей загрузки изображений...');

        const elements = document.querySelectorAll('[data-cashback-image]');
        const uniqueImagePaths = [];

        elements.forEach(function(element) {
            const imagePath = element.getAttribute('data-cashback-image');
            if (imagePath && imagePath.trim() !== '' && !uniqueImagePaths.includes(imagePath)) {
                uniqueImagePaths.push(imagePath);
            }
        });

        console.log('Найдено уникальных изображений для загрузки:', uniqueImagePaths.length);

        if (uniqueImagePaths.length === 0) {
            console.log('📸 Нет изображений для загрузки');
            return;
        }

        // Обновляем UI для загрузки изображений
        document.getElementById('imageProgress').textContent = '0';
        document.getElementById('imageTotal').textContent = uniqueImagePaths.length;
        document.getElementById('imagesStage').style.display = 'block';
        document.getElementById('imagesStage').classList.add('active');

        let loadedCount = 0;
        const loadPromises = uniqueImagePaths.map(function(imagePath) {
            return loadAndCacheImage(imagePath)
                .then(function() {
                    loadedCount++;
                    document.getElementById('imageProgress').textContent = loadedCount;
                    console.log(`✅ Загружено (${loadedCount}/${uniqueImagePaths.length}):`, imagePath);
                })
                .catch(function(error) {
                    loadedCount++;
                    document.getElementById('imageProgress').textContent = loadedCount;
                    console.error('❌ Ошибка загрузки изображения:', imagePath, error);
                });
        });

        await Promise.allSettled(loadPromises);
        console.log('🎉 Все изображения загружены!');
    }

    /**
     * Основная функция блокирующей загрузки
     */
    async function performBlockingLoad() {
        try {
            console.log('🌐 Начало блокирующей загрузки всех данных...');

            // Показываем loader
            const pageLoader = document.getElementById('pageLoader');
            const mainContent = document.getElementById('mainContent');

            pageLoader.style.display = 'flex';
            mainContent.style.opacity = '0';

            // Активируем первый этап
            document.getElementById('livewireStage').classList.add('active');

            const dataLoader = new DataLoader('{{ $user->search_token }}');

            // Этап 1: Загрузка свежих данных
            const dataLoaded = await dataLoader.waitForLivewireAndLoad();

            if (dataLoaded) {
                document.getElementById('livewireStage').classList.remove('active');
                document.getElementById('livewireStage').classList.add('complete');
                document.getElementById('livewireStage').querySelector('.stage-icon').textContent = '✅';
                document.getElementById('livewireStage').querySelector('.stage-text').textContent = 'Данные кешбэков загружены';
            }

            // Этап 2: Загрузка изображений (с очисткой кеша)
            if (navigator.onLine) {
                console.log('🌐 Очищаю старый кеш изображений перед загрузкой свежих...');
                const removedCount = clearImageCache();
                console.log(`🗑️ Очищено ${removedCount} изображений из кеша`);
            }
            await blockingLoadImagesWithProgress();

            // Этап 3: Завершение
            document.getElementById('imagesStage').classList.remove('active');
            document.getElementById('imagesStage').classList.add('complete');
            document.getElementById('imagesStage').querySelector('.stage-icon').textContent = '✅';
            document.getElementById('imagesStage').querySelector('.stage-text').textContent = 'Скриншоты загружены';

            document.getElementById('completeStage').style.display = 'block';
            document.getElementById('completeStage').classList.add('active');

            // Показываем контент с плавной анимацией
            setTimeout(() => {
                pageLoader.style.display = 'none';
                mainContent.style.opacity = '1';
                mainContent.style.transition = 'opacity 0.5s ease-in-out';
                console.log('🎉 Блокирующая загрузка завершена!');
            }, 500);

        } catch (error) {
            console.error('❌ Критическая ошибка блокирующей загрузки:', error);

            // В случае ошибки все равно показываем контент
            document.getElementById('pageLoader').style.display = 'none';
            document.getElementById('mainContent').style.opacity = '1';
        }
    }

    /**
     * Инициализация при загрузке страницы
     */
    document.addEventListener('DOMContentLoaded', function () {
        console.log('🔄 Страница загружена, начинаю инициализацию...');

        // Проверяем наличие интернета
        if (navigator.onLine) {
            console.log('🌐 Есть интернет - начинаю блокирующую загрузку');
            performBlockingLoad();
        } else {
            console.log('📶 Нет интернета - показываю контент из кеша');
            document.getElementById('mainContent').style.opacity = '1';
            console.log('📦 Работаю в оффлайн режиме');
        }

        // Отслеживание изменений подключения
        window.addEventListener('online', function() {
            console.log('🌐 Появилось подключение к интернету');
            setTimeout(() => location.reload(), 1000);
        });

        window.addEventListener('offline', function() {
            console.log('📶 Потеряно подключение к интернету');
        });

        // Модальное окно для MCC кодов
        $('#modal').on('show.bs.modal', function (event) {
            let button = $(event.relatedTarget);
            let note = button.data('mcc');
            let modal = $(this);
            modal.find('.modal-div').text(note);
        });
    });

    // Регистрируем подходящий Service Worker
    if ('serviceWorker' in navigator) {
        // Определяем Safari или другой браузер
        const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

        // Выбираем правильный Service Worker
        const swFile = isSafari ? '/sw-safari.js' : '/sw-offline.js';

        navigator.serviceWorker.register(swFile)
            .then(registration => {
                console.log('Service Worker зарегистрирован:', swFile, registration);

                // Для Safari - показываем уведомление
                if (isSafari) {
                    console.log('🍎 Используется Safari Compatible Service Worker');
                } else {
                    console.log('🌐 Используется Full Featured Service Worker');
                }
            })
            .catch(error => {
                console.error('Ошибка регистрации Service Worker:', swFile, error);
            });
    }

    /**
     * Очистить все закешированные изображения из localStorage
     * @returns {number} Количество удаленных изображений
     */
    function clearImageCache() {
        try {
            console.log('🗑️ Начинаю очистку кеша изображений...');
            const keys = Object.keys(localStorage);
            let removedCount = 0;

            keys.forEach(function(key) {
                if (key.startsWith('cashback_img_')) {
                    localStorage.removeItem(key);
                    removedCount++;
                }
            });

            console.log('✅ Очищено изображений из кеша:', removedCount);
            return removedCount;
        } catch (error) {
            console.error('❌ Ошибка при очистке кеша изображений:', error);
            return 0;
        }
    }

    /**
     * Делаем функции глобально доступными после их определения
     */
    window.getCachedImage = getCachedImage;
    window.loadAndCacheImage = loadAndCacheImage;
    window.saveImageToCache = saveImageToCache;
    window.clearImageCache = clearImageCache;

    console.log('✅ Глобальные функции изображений зарегистрированы');

</script>

<style>
/* Page Loader Styles */
.page-loader-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    color: white;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.page-loader-content {
    text-align: center;
    max-width: 450px;
    padding: 30px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.page-loader-content h3 {
    margin: 20px 0;
    font-size: 24px;
    font-weight: 600;
    color: #ffffff;
}

.spinner {
    border: 4px solid rgba(255, 255, 255, 0.3);
    border-top: 4px solid #007bff;
    border-radius: 50%;
    width: 60px;
    height: 60px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.loading-stages {
    margin-top: 25px;
    text-align: left;
}

.stage {
    display: flex;
    align-items: center;
    padding: 15px;
    margin: 10px 0;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border-left: 4px solid transparent;
    transition: all 0.3s ease;
    font-size: 14px;
}

.stage.active {
    background: rgba(0, 123, 255, 0.2);
    border-left-color: #007bff;
    box-shadow: 0 0 20px rgba(0, 123, 255, 0.3);
}

.stage.complete {
    background: rgba(40, 167, 69, 0.2);
    border-left-color: #28a745;
    box-shadow: 0 0 20px rgba(40, 167, 69, 0.3);
}

.stage-icon {
    margin-right: 12px;
    font-size: 18px;
    min-width: 25px;
    text-align: center;
}

.stage-text {
    font-weight: 500;
    flex: 1;
}

/* Анимация появления контента */
#mainContent {
    transition: opacity 0.5s ease-in-out;
}

/* Адаптивность для мобильных устройств */
@media (max-width: 576px) {
    .page-loader-content {
        margin: 20px;
        padding: 20px;
        max-width: calc(100% - 40px);
    }

    .page-loader-content h3 {
        font-size: 20px;
    }

    .stage {
        padding: 12px;
        font-size: 13px;
    }

    .stage-icon {
        font-size: 16px;
        margin-right: 10px;
    }

    .spinner {
        width: 50px;
        height: 50px;
    }
}
</style>

</body>
</html>

