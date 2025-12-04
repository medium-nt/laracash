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
                <div class="input-group">
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
            try {
                // Создаем уникальный ключ для localStorage
                const cacheKey = 'cashback_img_' + imagePath;
                // Получаем данные из localStorage
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
                // Создаем уникальный ключ для localStorage
                const cacheKey = 'cashback_img_' + imagePath;
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
            return new Promise((resolve, reject) => {
                // Полный URL к изображению с версионированием для обхода кеша
                const timestamp = Date.now();
                const fullUrl = '/storage/card_cashback_image/' + imagePath + '?v=' + timestamp;

                console.log('Загрузка изображения:', fullUrl);

                // Создаем новый Image объект для загрузки
                const img = new Image();
                img.crossOrigin = 'Anonymous'; // Для CORS если нужно

                img.onload = function() {
                    // Создаем canvas для конвертации в base64
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = img.width;
                    canvas.height = img.height;

                    // Рисуем изображение на canvas
                    ctx.drawImage(img, 0, 0);

                    // Конвертируем в base64
                    const base64 = canvas.toDataURL('image/jpeg', 0.8);

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
                const keys = Object.keys(localStorage);
                let removedCount = 0;

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
            const elements = document.querySelectorAll('[data-cashback-image]');

            // Создаем массив для хранения уникальных путей изображений
            const uniqueImagePaths = [];

            // Собираем все уникальные пути к изображениям
            elements.forEach(function(element) {
                const imagePath = element.getAttribute('data-cashback-image');
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
            const loader = document.getElementById('imageLoader');
            const progressText = document.getElementById('loadingProgress');
            const totalText = document.getElementById('totalImages');

            loader.style.display = 'flex';
            progressText.textContent = '0';
            totalText.textContent = uniqueImagePaths.length;

            // Загружаем все изображения последовательно с прогрессом
            let loadedCount = 0;
            const loadPromises = uniqueImagePaths.map(function(imagePath) {
                return loadAndCacheImage(imagePath)
                    .then(function() {
                        loadedCount++;
                        progressText.textContent = loadedCount;
                        console.log(`✅ Загружено (${loadedCount}/${uniqueImagePaths.length}):`, imagePath);
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
            const elements = document.querySelectorAll('[data-cashback-image]');

            // Создаем Set для хранения уникальных путей изображений
            const uniqueImagePaths = new Set();

            // Собираем все уникальные пути к изображениям
            elements.forEach(function(element) {
                const imagePath = element.getAttribute('data-cashback-image');
                // Добавляем только если путь не пустой и еще не добавлен
                if (imagePath && imagePath.trim() !== '') {
                    uniqueImagePaths.add(imagePath);
                }
            });

            console.log('Найдено уникальных изображений:', uniqueImagePaths.size);

            // Загружаем изображения в фоне (асинхронно)
            const loadPromises = [];

            uniqueImagePaths.forEach(function(imagePath) {
                // Проверяем есть ли изображение в кеше
                const cachedImage = getCachedImage(imagePath);

                if (!cachedImage) {
                    console.log('Изображение не найдено в кеше, загружаем:', imagePath);
                    // Добавляем Promise в массив загрузок
                    const loadPromise = loadAndCacheImage(imagePath)
                        .then(() => {
                            console.log('✅ Изображение закешировано:', imagePath);
                        })
                        .catch((error) => {
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
         * NOTE: Основная логика загрузки теперь в search/index.blade.php
         */
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔄 SearchComponent: Инициализация изображений...');

            // При наличии интернета - очищаем старый кеш изображений
            // Это нужно сделать до того как начнется загрузка в search/index.blade.php
            if (navigator.onLine) {
                console.log('🌐 Очищаю старый кеш изображений...');
                const removedCount = clearImageCache();
                console.log(`🗑️ Очищено ${removedCount} изображений из кеша`);
            } else {
                console.log('📶 Режим оффлайн - сохраняю существующий кеш');
            }
        });

        // NOTE: Слушатели онлайн/офлайн перенесены в search/index.blade.php

        /**
         * Простая логика модального окна - используем изображения из localStorage
         */
        $('#cashbackModal').on('show.bs.modal', function (event) {
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

            if (cachedImage) {
                // ✅ Изображение есть в localStorage - показываем сразу
                console.log('📦 Изображение из localStorage:', src);
                modal.find('#modalCardId').text('ID карты: ' + cardId);
                modal.find('#modalImage').attr('src', cachedImage);
                modal.find('#modalImage').attr('alt', 'Скриншот кешбэка');
            } else {
                // ❌ Изображения нет в кеше - показываем ошибку или загружаем
                console.log('❌ Изображение не найдено в localStorage:', src);

                if (navigator.onLine) {
                    // Только при интернете пробуем загрузить
                    console.log('🌐 Загрузка отсутствующего изображения:', src);

                    // Показываем индикатор загрузки
                    modal.find('#modalImage').attr('src', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjUiIGN5PSIyNSIgcj0iMjAiIHN0cm9rZT0iIzAwN2JmZiIgc3Ryb2tlLXdpZHRoPSIzIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1kYXNoYXJyYXk9IjEwIDEwIj4KPGFuaW1hdGUgYXR0cmlidXRlTmFtZT0ic3Ryb2tlLWRhc2hvZmZzZXQiIHZhbHVlcz0iMTAwIDA7MTAwIDA7MTAwIDA7MDtDMCAxMDAiIGR1cj0iMXMiIHJlcGVhdENvdW50PSJpbmRlZmluaXRlIi8+CjwvY2lyY2xlPgo8L3N2Zz4=');
                    modal.find('#modalImage').attr('alt', 'Загрузка...');
                    modal.find('#modalCardId').text('ID карты: ' + cardId + ' (загрузка...)');

                    // Загружаем изображение
                    loadAndCacheImage(src)
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
                    // Нет интернета и нет кеша
                    modal.find('#modalImage').attr('alt', 'Скриншот недоступен (оффлайн режим)');
                    modal.find('#modalImage').attr('src', '');
                    modal.find('#modalCardId').text('ID карты: ' + cardId + ' (недоступно)');
                }
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

</div>
