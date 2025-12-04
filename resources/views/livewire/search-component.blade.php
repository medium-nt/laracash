<div class="container">
    <!-- Loader для блокирующей загрузки изображений -->
    <div id="imageLoader" class="image-loader-overlay" style="display: none;">
        <div class="image-loader-content">
            <div class="spinner"></div>
            <p>Загрузка свежих скриншотов кешбэков...</p>
            <div class="progress-text">
                <span id="loadingProgress">0</span> / <span id="totalImages">0</span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="search-form">
                <!-- Офлайн индикатор -->
                <div id="offlineIndicator" class="offline-indicator" style="display: none;">
                    <div class="offline-content">
                        <i class="fas fa-wifi"></i>
                        <span>Офлайн режим</span>
                        <small>Поиск недоступен без интернета</small>
                    </div>
                </div>

                <div id="searchInputGroup" class="input-group">
                    <a href="{{ route('search.index', ['token' => $user->search_token]) }}"
                       id="back-btn"
                       class="btn btn-default btn-l mr-0"
                    >
                        <i class="fas fa-angle-double-left"></i>
                    </a>

                    <input class="form-control" wire:model.live.debounce.750ms="search" type="text" name="searchInput" id="searchInput"
                           aria-describedby="search-btn" placeholder="категория и ключевое слово..." autofocus>
                    <label for="search" class="sr-only">Search</label>

                    <a href="/login" class="btn btn-default btn-r ml-0"><i class="fas fa-sign-in-alt"></i></a>
                </div>
                <div wire:loading class="loader">
                    <span>Загрузка...</span>
                </div>
            </div>

            @if (count($filteredCategoriesCashback) == 0)
                <div class="category alert alert-warning" role="alert">
                    По вашему запросу ничего не найдено.
                </div>
            @endif

            @isset($filteredCategoriesCashback)
                @php
                    $date = '1999-01-01 00:00:00';
                @endphp

                @foreach($filteredCategoriesCashback as $category => $cardList)
                    <div class="category mt-5">
                        <h5>{{ $category }}</h5>
                    </div>

                    <table class="topics-table">
                        <tbody>
                        @isset($cardList)
                            @foreach($cardList as $card)
                                <tr class="topic-item-1">
                                    <td>
                                        <span class="badge"
                                              style="background-color: {{$card->card_color}}; color: white;"
                                              data-target="#cashbackModal"
                                              data-card-id="{{ $card->card_id }}"
                                              data-cashback-image="{{ $card->cashback_image }}"
                                              data-toggle="modal">
                                            {{ $card->card_number }} {{ $card->bank_title }}
                                        </span>

                                        @if($card->mcc != '')
                                            <i class="mcc {{$card->id}} fas fa-exclamation-circle"
                                               style="color: #007bff;"
                                               data-toggle='modal'
                                               data-target='#modal'
                                               data-mcc='{{ $card->mcc }}'
                                            ></i>
                                        @endif
                                    </td>
                                    <td style="width: 100px">{{ $card->cashback_percentage }}%</td>
                                </tr>

                                @php
                                    if ($date < $card->updated_at) {
                                        $date = $card->updated_at;
                                    }
                                @endphp
                            @endforeach
                        @endisset
                        </tbody>
                    </table>
                @endforeach

                <div class="category mb-5">
                    @if(!isset($card->cashback_percentage))
{{--                        У вас нет карт с такой категорией кешбека--}}
                    @else
                        @php
                            $dateFormat = ($date != '0000-00-00 00:00:00') ? now()->parse($date)->format('d/m/Y') : 'Нет данных';
                        @endphp
                        <br>
                        <small>Дата актуальности кешбека: <b>{{ $dateFormat }}</b></small>
                    @endif
                </div>
            @endisset
        </div>
    </div>

    <!-- Модальное окно -->
    <div class="modal fade" id="cashbackModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content bg-white">
                <!-- Кнопка закрытия -->
                <div class="modal-header border-0">
                    <button type="button" class="close text-danger" data-dismiss="modal" aria-label="Закрыть">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <!-- Тело модалки -->
                <div class="modal-body text-center">
                    <div id="modalCardId" style="margin-bottom: 10px; font-weight: bold;"></div>
                    <img id="modalImage" src="" alt="Скриншот кешбэка" style="max-width: 100%; height: auto;">
                </div>
            </div>
        </div>
    </div>

    <script>
        /**
         * Получить base64 строку изображения из localStorage
         * @param {string} imagePath - путь к изображению
         * @returns {string|null} base64 строка или null если не найдено
         */
        function getCachedImage(imagePath) {
            // Используем глобальную функцию из search/index.blade.php
            return window.getCachedImage ? window.getCachedImage(imagePath) : null;
        }

        /**
         * Сохранить base64 изображение в localStorage
         * @param {string} imagePath - путь к изображению
         * @param {string} base64Data - base64 строка изображения
         */
        function saveImageToCache(imagePath, base64Data) {
            try {
                // Создаем уникальный ключ для localStorage
                var cacheKey = 'cashback_img_' + imagePath;
                // Сохраняем изображение в localStorage
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
            return new Promise(function(resolve, reject) {
                // Полный URL к изображению с версионированием для обхода кеша
                var timestamp = Date.now();
                var fullUrl = '/storage/card_cashback_image/' + imagePath + '?v=' + timestamp;

                console.log('Загрузка изображения:', fullUrl);

                // Создаем новый Image объект для загрузки
                var img = new Image();
                img.crossOrigin = 'Anonymous'; // Для CORS если нужно

                img.onload = function() {
                    // Создаем canvas для конвертации в base64
                    var canvas = document.createElement('canvas');
                    var ctx = canvas.getContext('2d');
                    canvas.width = img.width;
                    canvas.height = img.height;

                    // Рисуем изображение на canvas
                    ctx.drawImage(img, 0, 0);

                    // Конвертируем в base64
                    var base64 = canvas.toDataURL('image/jpeg', 0.8);

                    // Сохраняем в кеш
                    saveImageToCache(imagePath, base64);

                    console.log('Изображение успешно загружено и сохранено:', imagePath);
                    resolve(base64);
                };

                img.onerror = function() {
                    console.error('Ошибка загрузки изображения:', fullUrl);
                    reject(new Error('Не удалось загрузить изображение: ' + imagePath));
                };

                // Начинаем загрузку
                img.src = fullUrl;
            });
        }

        /**
         * Очистить все закешированные изображения из localStorage
         */
        function clearImageCache() {
            try {
                console.log('🗑️ Очистка кеша изображений...');
                var keys = Object.keys(localStorage);
                var removedCount = 0;

                keys.forEach(function(key) {
                    if (key.startsWith('cashback_img_')) {
                        localStorage.removeItem(key);
                        removedCount++;
                    }
                });

                console.log('✅ Удалено изображений из кеша:', removedCount);
                return removedCount;
            } catch (error) {
                console.error('❌ Ошибка при очистке кеша изображений:', error);
                return 0;
            }
        }

        /**
         * Блокирующая функция кеширования всех изображений с progress bar
         * Загружает ВСЕ изображения заново при наличии интернета
         */
        function blockingCacheAllImages() {
            console.log('🔄 Начало блокирующей загрузки изображений...');

            // Находим все элементы с атрибутом data-cashback-image
            var elements = document.querySelectorAll('[data-cashback-image]');

            // Создаем массив для хранения уникальных путей изображений
            var uniqueImagePaths = [];

            // Собираем все уникальные пути к изображениям
            elements.forEach(function(element) {
                var imagePath = element.getAttribute('data-cashback-image');
                // Добавляем только если путь не пустой и еще не добавлен
                if (imagePath && imagePath.trim() !== '') {
                    if (!uniqueImagePaths.includes(imagePath)) {
                        uniqueImagePaths.push(imagePath);
                    }
                }
            });

            console.log('Найдено уникальных изображений для загрузки:', uniqueImagePaths.length);

            if (uniqueImagePaths.length === 0) {
                console.log('📸 Нет изображений для загрузки');
                return Promise.resolve();
            }

            // Показываем loader
            var loader = document.getElementById('imageLoader');
            var progressText = document.getElementById('loadingProgress');
            var totalText = document.getElementById('totalImages');

            loader.style.display = 'flex';
            progressText.textContent = '0';
            totalText.textContent = uniqueImagePaths.length;

            // Загружаем все изображения последовательно с прогрессом
            var loadedCount = 0;
            var loadPromises = uniqueImagePaths.map(function(imagePath) {
                return loadAndCacheImage(imagePath)
                    .then(function() {
                        loadedCount++;
                        progressText.textContent = loadedCount;
                        console.log('✅ Загружено (' + loadedCount + '/' + uniqueImagePaths.length + '):', imagePath);
                    })
                    .catch(function(error) {
                        loadedCount++;
                        progressText.textContent = loadedCount;
                        console.error('❌ Ошибка загрузки изображения:', imagePath, error);
                        // Продолжаем загрузку даже с ошибками
                    });
            });

            // Ждем завершения всех загрузок
            return Promise.allSettled(loadPromises)
                .then(function() {
                    console.log('🎉 Все изображения загружены!');
                    // Скрываем loader
                    loader.style.display = 'none';
                })
                .catch(function() {
                    console.log('⚠️ Загрузка завершена с ошибками');
                    // Все равно скрываем loader
                    loader.style.display = 'none';
                });
        }

        /**
         * Основная функция кеширования всех изображений на странице (для оффлайн режима)
         * Находит все элементы с data-cashback-image и загружает отсутствующие в кеш
         */
        function cacheCashbackImages() {
            console.log('Начало кеширования изображений...');

            // Находим все элементы с атрибутом data-cashback-image
            var elements = document.querySelectorAll('[data-cashback-image]');

            // Создаем массив для хранения уникальных путей изображений
            var uniqueImagePaths = new Set();

            // Собираем все уникальные пути к изображениям
            elements.forEach(function(element) {
                var imagePath = element.getAttribute('data-cashback-image');
                // Добавляем только если путь не пустой и еще не добавлен
                if (imagePath && imagePath.trim() !== '') {
                    uniqueImagePaths.add(imagePath);
                }
            });

            console.log('Найдено уникальных изображений:', uniqueImagePaths.size);

            // Загружаем изображения в фоне (асинхронно)
            var loadPromises = [];

            uniqueImagePaths.forEach(function(imagePath) {
                // Проверяем есть ли изображение в кеше
                var cachedImage = getCachedImage(imagePath);

                if (!cachedImage) {
                    console.log('Изображение не найдено в кеше, загружаем:', imagePath);
                    // Добавляем Promise в массив загрузок
                    var loadPromise = loadAndCacheImage(imagePath)
                        .then(function() {
                            console.log('✅ Изображение закешировано:', imagePath);
                        })
                        .catch(function(error) {
                            console.error('❌ Ошибка кеширования изображения:', imagePath, error);
                        });

                    loadPromises.push(loadPromise);
                } else {
                    console.log('✅ Изображение уже в кеше:', imagePath);
                }
            });

            // Ждем завершения всех загрузок
            Promise.allSettled(loadPromises).then(function() {
                console.log('🎉 Кеширование изображений завершено!');
            });
        }

        /**
         * Инициализация при загрузке страницы - управление изображениями
         * NOTE: Основная логика загрузки и очистки теперь в search/index.blade.php
         */
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔄 SearchComponent: Инициализация...');
            console.log('📦 Очистка и загрузка изображений управляется в search/index.blade.php');

            // Проверяем наличие элементов с data-cashback-image
            var cashbackElements = document.querySelectorAll('[data-cashback-image]');
            console.log('🔍 SearchComponent: Найдено элементов с data-cashback-image:', cashbackElements.length);

            cashbackElements.forEach(function(element, index) {
                console.log('🔍 Элемент ' + index + ':', {
                    src: element.getAttribute('data-cashback-image'),
                    cardId: element.getAttribute('data-card-id'),
                    hasModalTarget: element.getAttribute('data-target') === '#cashbackModal',
                    hasToggle: element.getAttribute('data-toggle') === 'modal'
                });
            });

            // Проверяем наличие модального окна
            var modal = document.getElementById('cashbackModal');
            console.log('🔍 SearchComponent: Модальное окно #cashbackModal найдено:', !!modal);

            // Проверяем наличие изображения в модальном окне
            var modalImage = document.getElementById('modalImage');
            console.log('🔍 SearchComponent: Изображение #modalImage найдено:', !!modalImage);

            // Небольшая задержка чтобы search/index успел выполниться первым
            setTimeout(function() {
                console.log('🔄 SearchComponent: Ожидание завершения инициализации search/index...');
            }, 100);
        });

        // NOTE: Слушатели онлайн/офлайн перенесены в search/index.blade.php

        /**
         * Управление оффлайн режимом для поиска
         */
        class OfflineSearchManager {
            constructor() {
                // Всегда начинаем с реальной проверки сети, а не с navigator.onLine
                this.isOnline = false; // По умолчанию считаем оффлайн
                this.searchInput = document.getElementById('searchInput');
                this.searchInputGroup = document.getElementById('searchInputGroup');
                this.offlineIndicator = document.getElementById('offlineIndicator');
                this.livewireComponent = null;

                this.init();
            }

            init() {
                // Ждем загрузки Livewire компонента
                this.waitForLivewire();

                // Обработчики событий онлайн/офлайн
                window.addEventListener('online', () => this.handleOnline());
                window.addEventListener('offline', () => this.handleOffline());

                // Обработчик ввода в поиске
                if (this.searchInput) {
                    this.searchInput.addEventListener('input', (e) => this.handleSearchInput(e));
                    this.searchInput.addEventListener('focus', () => this.handleSearchFocus());
                }

                // Проверяем реальный статус сети перед обновлением UI
                this.checkAndUpdateNetworkStatus();
            }

            waitForLivewire() {
                var maxAttempts = 50; // 50 попыток по 100мс = 5 секунд
                var attempts = 0;
                var self = this;

                function checkLivewire() {
                    try {
                        var livewireElement = document.querySelector('[wire\\:id]');
                        if (livewireElement && livewireElement.wireId) {
                            self.livewireComponent = Livewire.find(livewireElement.wireId);
                            if (self.livewireComponent) {
                                console.log('✅ OfflineSearchManager: Livewire компонент найден');
                                return;
                            }
                        }
                    } catch (e) {
                        // Игнорируем ошибки во время ожидания
                    }

                    attempts++;
                    if (attempts < maxAttempts) {
                        setTimeout(checkLivewire, 100);
                    } else {
                        console.warn('⚠️ OfflineSearchManager: Livewire компонент не найден');
                    }
                }

                checkLivewire();
            }

            handleOnline() {
                console.log('🌐 Сеть появилась, включаю поиск');
                this.isOnline = true;
                this.updateUI();
                this.enableLivewireSearch();
            }

            handleOffline() {
                console.log('📶 Сеть пропала, отключаю поиск');
                this.isOnline = false;
                this.updateUI();
                this.disableLivewireSearch();
            }

            updateUI() {
                if (this.isOnline) {
                    // Онлайн режим
                    if (this.offlineIndicator) {
                        this.offlineIndicator.style.display = 'none';
                    }
                    if (this.searchInputGroup) {
                        this.searchInputGroup.style.display = 'flex';
                    }
                    if (this.searchInput) {
                        this.searchInput.disabled = false;
                        this.searchInput.placeholder = 'категория и ключевое слово...';
                    }
                } else {
                    // Офлайн режим
                    if (this.offlineIndicator) {
                        this.offlineIndicator.style.display = 'block';
                    }
                    if (this.searchInputGroup) {
                        this.searchInputGroup.style.display = 'none';
                    }
                }
            }

            disableLivewireSearch() {
                if (!this.livewireComponent) return;

                try {
                    // Отключаем Livewire реактивность для поиска
                    this.livewireComponent.set('search', '');
                    this.livewireComponent.removeProperty('search');

                    console.log('✅ Livewire поиск отключен');
                } catch (error) {
                    console.error('❌ Ошибка отключения Livewire поиска:', error);
                }
            }

            enableLivewireSearch() {
                if (!this.livewireComponent) return;

                try {
                    // Включаем Livewire реактивность обратно
                    this.livewireComponent.addProperty('search');

                    console.log('✅ Livewire поиск включен');
                } catch (error) {
                    console.error('❌ Ошибка включения Livewire поиска:', error);
                }
            }

            handleSearchInput(event) {
                if (!this.isOnline) {
                    // Блокируем ввод в оффлайн режиме
                    event.preventDefault();
                    event.target.value = '';
                    event.target.blur();

                    // Показываем оффлайн индикатор если еще не виден
                    if (this.offlineIndicator) {
                        this.offlineIndicator.style.display = 'block';
                    }

                    return false;
                }
            }

            handleSearchFocus() {
                if (!this.isOnline && this.searchInput) {
                    // Не даем фокуситься на поиск в оффлайн режиме
                    this.searchInput.blur();
                }
            }

            checkAndUpdateNetworkStatus() {
                var self = this;

                try {
                    window.checkNetworkConnectivity().then(function(isOnline) {
                        console.log('🔍 Проверка сети:', isOnline ? 'Онлайн' : 'Офлайн');

                        if (self.isOnline !== isOnline) {
                            self.isOnline = isOnline;
                            self.updateUI();
                        }
                    }).catch(function(error) {
                        console.error('❌ Ошибка проверки сети:', error);
                        // В случае ошибки считаем что оффлайн
                        if (self.isOnline !== false) {
                            self.isOnline = false;
                            self.updateUI();
                        }
                    });
                } catch (error) {
                    console.error('❌ Ошибка проверки сети:', error);
                    // В случае ошибки считаем что оффлайн
                    if (self.isOnline !== false) {
                        self.isOnline = false;
                        self.updateUI();
                    }
                }
            }
        }

        // Глобальная функция проверки сети (копия метода из класса)
        window.checkNetworkConnectivity = function() {
            return new Promise(function(resolve) {
                var xhr = new XMLHttpRequest();
                xhr.open('HEAD', '/favicon.png', true);
                xhr.timeout = 3000;

                xhr.onload = function() {
                    resolve(xhr.status >= 200 && xhr.status < 300);
                };

                xhr.onerror = function() {
                    resolve(false);
                };

                xhr.ontimeout = function() {
                    resolve(false);
                };

                xhr.send();
            });
        }

        // Инициализация менеджера оффлайн режима
        document.addEventListener('DOMContentLoaded', function() {
            // Даем время на инициализацию Livewire
            setTimeout(function() {
                window.offlineSearchManager = new OfflineSearchManager();
            }, 1000);
        });

        // Глобальная функция проверки сети больше не нужна - она перенесена в класс

        /**
         * Простая логика модального окна - используем изображения из localStorage
         * Используем делегирование событий для динамических элементов
         */
        $(document).on('click', '[data-toggle="modal"][data-target="#cashbackModal"]', function (event) {
            console.log('🖱️ Клик по элементу модального окна detected!');
            event.preventDefault();

            var trigger = $(this);
            var cardId = trigger.data('card-id');
            var src = trigger.data('cashback-image');
            var modal = $('#cashbackModal');

            console.log('🔍 Modal Click: cardId =', cardId, 'src =', src);

            // Проверяем есть ли путь к изображению
            if (src === '') {
                console.log('❌ Modal Click: Путь к изображению пустой');
                modal.find('#modalImage').attr('alt', 'Скриншот карты не найден');
                modal.find('#modalImage').attr('src', '');
                modal.find('#modalCardId').text('ID карты: ' + cardId);
                modal.modal('show');
                return;
            }

            // --- ПРОСТАЯ ЛОГИКА: ИСПОЛЬЗУЕМ ЛОКАЛЬНЫЙ КЕШ ---

            var cachedImage = getCachedImage(src);

            // Детальное логирование для отладки
            console.log('🔍 Modal Click: Проверка изображения для src:', src);
            console.log('🔍 Modal Click: Проверка localStorage...');

            // Проверяем все ключи в localStorage
            var allKeys = Object.keys(localStorage).filter(function(key) {
                return key.startsWith('cashback_img_');
            });
            console.log('🔍 Modal Click: Все ключи изображений в localStorage:', allKeys);

            console.log('🔍 Modal Click: Найденный кеш:', cachedImage ? 'Да' : 'Нет');
            if (cachedImage) {
                console.log('🔍 Modal Click: Длина кеша:', cachedImage.length, 'первые 100 символов:', cachedImage.substring(0, 100));
            }

            if (cachedImage) {
                // ✅ Изображение есть в localStorage - показываем сразу
                console.log('📦 Modal Click: Изображение найдено в localStorage:', src);

                // Проверяем что элементы модального окна существуют
                var modalImage = modal.find('#modalImage');
                console.log('🔍 Modal Click: Элемент #modalImage найден:', modalImage.length > 0);

                if (modalImage.length > 0) {
                    console.log('🔍 Modal Click: Устанавливаем src в #modalImage');
                    modal.find('#modalCardId').text('ID карты: ' + cardId);
                    modal.find('#modalImage').attr('src', cachedImage);
                    modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');

                    // Проверяем что src установился
                    setTimeout(function() {
                        var finalSrc = modal.find('#modalImage').attr('src');
                        console.log('🔍 Modal Click: Финальный src изображения:', finalSrc ? 'Установлен' : 'Пустой');
                    }, 100);

                    modal.modal('show');
                } else {
                    console.error('❌ Modal Click: Элемент #modalImage не найден!');
                }
            } else {
                // ❌ Изображения нет в кеше - показываем ошибку или загружаем
                console.log('❌ Modal Click: Изображение не найдено в localStorage:', src);

                // Дополнительная проверка - пробуем найти с полным ключом
                var directKey = 'cashback_img_' + src;
                var directCache = localStorage.getItem(directKey);
                console.log('🔍 Modal Click: Прямой доступ по ключу', directKey, ':', directCache ? 'Найдено' : 'Не найдено');

                if (directCache) {
                    console.log('🔍 Modal Click: Используем прямой доступ из localStorage');
                    modal.find('#modalImage').attr('src', directCache);
                    modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');
                    modal.find('#modalCardId').text('ID карты: ' + cardId);
                    modal.modal('show');
                } else if (navigator.onLine) {
                    // Только при интернете пробуем загрузить
                    console.log('🌐 Modal Click: Загрузка отсутствующего изображения:', src);

                    // Показываем индикатор загрузки
                    modal.find('#modalImage').attr('src', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjUiIGN5PSIyNSIgcj0iMjAiIHN0cm9rZT0iIzAwN2JmZiIgc3Ryb2tlLXdpZHRoPSIzIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1kYXNoYXJyYXk9IjEwIDEwIj4KPGFuaW1hdGUgYXR0cmlidXRlTmFtZT0ic3Ryb2tlLWRhc2hvZmZzZXQiIHZhbHVlcz0iMTAwIDA7MTAwIDA7MTAwIDA7MDtDMCAxMDAiIGR1cj0iMXMiIHJlcGVhdENvdW50PSJpbmRlZmluaXRlIi8+CjwvY2lyY2xlPgo8L3N2Zz4=');
                    modal.find('#modalImage').attr('alt', 'Загрузка...');
                    modal.find('#modalCardId').text('ID карты: ' + cardId + ' (загрузка...)');
                    modal.modal('show');

                    // Загружаем изображение (используем глобальную функцию)
                    window.loadAndCacheImage(src)
                        .then(function(base64Image) {
                            console.log('✅ Modal Click: Изображение загружено и показано:', src);
                            modal.find('#modalImage').attr('src', base64Image);
                            modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');
                            modal.find('#modalCardId').text('ID карты: ' + cardId);
                        })
                        .catch(function(error) {
                            console.error('❌ Modal Click: Ошибка загрузки изображения:', src, error);
                            modal.find('#modalImage').attr('src', '');
                            modal.find('#modalImage').attr('alt', 'Ошибка загрузки скриншота');
                            modal.find('#modalCardId').text('ID карты: ' + cardId + ' (ошибка)');
                        });
                } else {
                    // Нет интернета и нет кеша - критическая ситуация!
                    console.error('❌ Modal Click: КРИТИЧЕСКАЯ ОШИБКА - НЕТ ИЗОБРАЖЕНИЯ В КЕШЕ И НЕТ ИНТЕРНЕТА!');
                    console.log('🔍 Modal Click: src для поиска:', src);
                    console.log('🔍 Modal Click: Прямая проверка localStorage.getItem(\'cashback_img_\' + src):', localStorage.getItem('cashback_img_' + src));

                    modal.find('#modalImage').attr('alt', 'Скриншот не найден! (проверьте консоль)');
                    modal.find('#modalImage').attr('src', '');
                    modal.find('#modalCardId').text('ID карты: ' + cardId + ' (ошибка кеша!)');
                    modal.modal('show');
                }
            }
        });

        /**
         * Fallback обработчик для старого события show.bs.modal на всякий случай
         */
        $('#cashbackModal').on('show.bs.modal', function (event) {
            console.log('🔄 Fallback: show.bs.modal событие вызвано');
            var trigger = $(event.relatedTarget);
            var cardId = trigger.data('card-id');
            var src = trigger.data('cashback-image');
            var modal = $(this);

            // Проверяем есть ли путь к изображению
            if (src === '') {
                modal.find('#modalImage').attr('alt', 'Скриншот карты не найден');
                modal.find('#modalImage').attr('src', '');
                return; // Выходим если нет изображения
            }

            // --- ПРОСТАЯ ЛОГИКА: ИСПОЛЬЗУЕМ ЛОКАЛЬНЫЙ КЕШ ---

            var cachedImage = getCachedImage(src);

            // Детальное логирование для отладки
            console.log('🔍 Модальное окно: Проверка изображения для src:', src);
            console.log('🔍 Модальное окно: Проверка localStorage...');

            // Проверяем все ключи в localStorage
            var allKeys = Object.keys(localStorage).filter(function(key) {
                return key.startsWith('cashback_img_');
            });
            console.log('🔍 Модальное окно: Все ключи изображений в localStorage:', allKeys);

            console.log('🔍 Модальное окно: Найденный кеш:', cachedImage ? 'Да' : 'Нет');
            if (cachedImage) {
                console.log('🔍 Модальное окно: Длина кеша:', cachedImage.length, 'первые 100 символов:', cachedImage.substring(0, 100));
            }

            if (cachedImage) {
                // ✅ Изображение есть в localStorage - показываем сразу
                console.log('📦 Модальное окно: Изображение найдено в localStorage:', src);

                // Проверяем что элементы модального окна существуют
                var modalImage = modal.find('#modalImage');
                console.log('🔍 Модальное окно: Элемент #modalImage найден:', modalImage.length > 0);

                if (modalImage.length > 0) {
                    console.log('🔍 Модальное окно: Устанавливаем src в #modalImage');
                    modal.find('#modalCardId').text('ID карты: ' + cardId);
                    modal.find('#modalImage').attr('src', cachedImage);
                    modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');

                    // Проверяем что src установился
                    setTimeout(function() {
                        var finalSrc = modal.find('#modalImage').attr('src');
                        console.log('🔍 Модальное окно: Финальный src изображения:', finalSrc ? 'Установлен' : 'Пустой');
                    }, 100);
                } else {
                    console.error('❌ Модальное окно: Элемент #modalImage не найден!');
                }
            } else {
                // ❌ Изображения нет в кеше - показываем ошибку или загружаем
                console.log('❌ Модальное окно: Изображение не найдено в localStorage:', src);

                // Дополнительная проверка - пробуем найти с полным ключом
                var directKey = 'cashback_img_' + src;
                var directCache = localStorage.getItem(directKey);
                console.log('🔍 Модальное окно: Прямой доступ по ключу', directKey, ':', directCache ? 'Найдено' : 'Не найдено');

                if (directCache) {
                    console.log('🔍 Модальное окно: Используем прямой доступ из localStorage');
                    modal.find('#modalImage').attr('src', directCache);
                    modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');
                }
            }

            if (navigator.onLine) {
                // Только при интернете пробуем загрузить
                console.log('🌐 Загрузка отсутствующего изображения:', src);

                // Показываем индикатор загрузки
                modal.find('#modalImage').attr('src', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjUiIGN5PSIyNSIgcj0iMjAiIHN0cm9rZT0iIzAwN2JmZiIgc3Ryb2tlLXdpZHRoPSIzIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1kYXNoYXJyYXk9IjEwIDEwIj4KPGFuaW1hdGUgYXR0cmlidXRlTmFtZT0ic3Ryb2tlLWRhc2hvZmZzZXQiIHZhbHVlcz0iMTAwIDA7MTAwIDA7MTAwIDA7MDtDMCAxMDAiIGR1cj0iMXMiIHJlcGVhdENvdW50PSJpbmRlZmluaXRlIi8+CjwvY2lyY2xlPgo8L3N2Zz4=');
                modal.find('#modalImage').attr('alt', 'Загрузка...');
                modal.find('#modalCardId').text('ID карты: ' + cardId + ' (загрузка...)');

                // Загружаем изображение (используем глобальную функцию)
                window.loadAndCacheImage(src)
                    .then(function(base64Image) {
                        console.log('✅ Изображение загружено и показано:', src);
                        modal.find('#modalImage').attr('src', base64Image);
                        modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');
                        modal.find('#modalCardId').text('ID карты: ' + cardId);
                    })
                    .catch(function(error) {
                        console.error('❌ Ошибка загрузки изображения:', src, error);
                        modal.find('#modalImage').attr('src', '');
                        modal.find('#modalImage').attr('alt', 'Ошибка загрузки скриншота');
                        modal.find('#modalCardId').text('ID карты: ' + cardId + ' (ошибка)');
                    });
            } else {
                // Нет интернета и нет кеша - критическая ситуация!
                console.error('❌ Модальное окно: КРИТИЧЕСКАЯ ОШИБКА - НЕТ ИЗОБРАЖЕНИЯ В КЕШЕ И НЕТ ИНТЕРНЕТА!');
                console.log('🔍 Модальное окно: src для поиска:', src);
                console.log('🔍 Модальное окно: Прямая проверка localStorage.getItem(\'cashback_img_\' + src):', localStorage.getItem('cashback_img_' + src));

                modal.find('#modalImage').attr('alt', 'Скриншот не найден! (проверьте консоль)');
                modal.find('#modalImage').attr('src', '');
                modal.find('#modalCardId').text('ID карты: ' + cardId + ' (ошибка кеша!)');
            }
        });
    </script>

    <style>
    .image-loader-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        color: white;
    }

    .image-loader-content {
        text-align: center;
        max-width: 300px;
    }

    .spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #007bff;
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .progress-text {
        font-size: 18px;
        font-weight: bold;
        margin-top: 10px;
    }

    .image-loader-overlay p {
        font-size: 16px;
        margin: 10px 0;
    }
    </style>

<style>
/* Офлайн индикатор */
.offline-indicator {
    background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
    border-radius: 10px;
    padding: 20px;
    margin: 30px auto;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(238, 90, 82, 0.3);
    text-align: center;
    color: white;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.8; }
    100% { opacity: 1; }
}

.offline-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.offline-content i {
    font-size: 32px;
    margin-bottom: 5px;
}

.offline-content span {
    font-size: 18px;
    font-weight: 600;
}

.offline-content small {
    font-size: 14px;
    opacity: 0.9;
    line-height: 1.3;
}

/* Адаптивность для мобильных */
@media (max-width: 576px) {
    .offline-indicator {
        margin: 20px;
        padding: 15px;
        max-width: calc(100% - 40px);
    }

    .offline-content i {
        font-size: 24px;
    }

    .offline-content span {
        font-size: 16px;
    }

    .offline-content small {
        font-size: 12px;
    }
}
</style>

</div>
