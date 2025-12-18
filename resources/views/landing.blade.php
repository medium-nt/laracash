<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Кешбэк по картам</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
          rel="stylesheet" crossorigin="anonymous">
    <style>
        /* Стили для калькулятора */
        .calculator-card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
        }

        #spending-slider {
            height: 8px;
            background: #e9ecef;
            outline: none;
            -webkit-appearance: none;
        }

        #spending-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            background: #0d6efd;
            cursor: pointer;
            border-radius: 50%;
            margin-top: -6px; /* Центрирование ползунка по высоте */
        }

        #spending-slider::-moz-range-thumb {
            width: 20px;
            height: 20px;
            background: #0d6efd;
            cursor: pointer;
            border-radius: 50%;
            border: none;
        }

    
        #savings-amount {
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .result-box {
            background-color: #f8f9fa;
            border-radius: 8px;
        }

        .pulse {
            animation: pulse 0.3s ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Мобильная адаптация */
        @media (max-width: 576px) {
            #spending-slider {
                height: 12px;
            }

            #spending-slider::-webkit-slider-thumb {
                width: 28px;
                height: 28px;
                margin-top: -8px; /* Центрирование для мобильной версии */
            }

            #spending-slider::-moz-range-thumb {
                width: 28px;
                height: 28px;
            }

            #savings-amount {
                font-size: 1.5rem;
            }

            .calculator-section h2 {
                font-size: 1.5rem;
            }

            .calculator-card {
                margin: 0 10px;
            }
        }
    </style>
</head>
<body>

<!-- Заголовок -->
<header class="container-fluid p-5 bg-primary text-light text-center">
    <h1 class="display-1">Кешбэк на максимум!</h1>
    <p class="lead">Получайте максимальный кэшбэк с каждой покупки!</p>
</header>

<!-- Описание услуги -->
<section class="container my-5">
    <div class="row">
        <div class="col-md-6 order-md-2">
{{--            <img src="images/card.jpg" alt="Кредитная карта" class="img-fluid">--}}
            @if (Route::has('login'))
                <nav class="-mx-3 flex flex-1 justify-end">
                    @auth
                        <a href="{{ url('/home') }}" class="btn btn-success">
                            Войти в систему
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-success">
                            Войти в систему
                        </a>

                        {{--@if (Route::has('register'))--}}
                        {{--    <a href="{{ route('register') }}" class="btn btn-primary">--}}
                        {{--        Register--}}
                        {{--    </a>--}}
                        {{--@endif--}}
                    @endauth
                </nav>
            @endif
        </div>
        <div class="col-md-6 order-md-1">
            <h2>Что такое кешбэк?</h2>
            <p>
                Кешбэк — это возврат части потраченных денег на вашу карту после совершения покупок.
                Это удобный способ сэкономить на повседневных расходах.
            </p>
            <p>
                Но помнить по какой карте у вас на что кешбек — не так просто.
            </p>
            <p>
                Мы предлагаем вам систему, которая позволит вести учет кешбека по всем
                вашим картам и выбирать карту с максимальным кешбеком при каждой покупке.
            </p>

        </div>
    </div>
</section>

<!-- Калькулятор экономии -->
<section class="calculator-section bg-light py-5">
    <div class="container">
        <h2 class="text-center mb-5">Узнайте, сколько вы сэкономите!</h2>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card calculator-card">
                    <div class="card-body p-4">
                        <!-- Ползунок -->
                        <div class="mb-4">
                            <label class="form-label">Ваши ежемесячные траты по картам:</label>
                            <input type="range" class="form-range" id="spending-slider"
                                   min="0" max="500000" step="1000" value="50000">
                            <div class="d-flex justify-content-between text-muted small">
                                <span>0 ₽</span>
                                <span id="current-spending">50 000 ₽</span>
                                <span>500 000 ₽</span>
                            </div>
                        </div>

                        <!-- Результат -->
                        <div class="result-box text-center py-3">
                            <p class="mb-2">Ваша экономия в месяц:</p>
                            <h2 class="text-success" id="savings-amount">1 000 ₽</h2>
                            <p class="text-muted mb-0">Ваша экономия в год: <span id="yearly-savings">12 000 ₽</span></p>
                        </div>

                        <!-- Призыв к действию -->
                        <div class="text-center mt-4">
                            <a href="{{ route('login') }}" class="btn btn-primary btn-lg">
                                Начать экономить больше!
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Преимущества -->
<section class="container my-5">
    <h2 class="text-center mb-4">Преимущества нашего сервиса</h2>
    <div class="row gx-5 gy-4">
        <div class="col-md-4">
            <i class="bi bi-cash-stack fs-1 text-success"></i>
            <h3>Высокий процент возврата</h3>
            <p>Получайте до 10% возврата с каждой покупки.</p>
        </div>
        <div class="col-md-4">
            <i class="bi bi-card-checklist fs-1 text-info"></i>
            <h3>Широкая сеть партнеров</h3>
            <p>Наши партнеры предлагают выгодные условия для всех категорий товаров.</p>
        </div>
        <div class="col-md-4">
            <i class="bi bi-shield-check fs-1 text-warning"></i>
            <h3>Безопасность транзакций</h3>
            <p>Все ваши данные защищены современными технологиями шифрования.</p>
        </div>
    </div>
</section>

<!-- Отзывы клиентов -->
<section class="container my-5">
    <h2 class="text-center mb-4">Отзывы наших клиентов</h2>
    <div class="row gx-5 gy-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <p>"Очень доволен сервисом! Получаю хорошие бонусы за покупки."</p>
                    <footer class="blockquote-footer">Иван Иванов</footer>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <p>"Удобный сервис, быстро получил карту и уже пользуюсь бонусами."</p>
                    <footer class="blockquote-footer">Марина Петрова</footer>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <p>"Простая регистрация и быстрое получение карты. Рекомендую!"</p>
                    <footer class="blockquote-footer">Алексей Смирнов</footer>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Контакты -->
<section class="container my-5">
    <h2 class="text-center mb-4">Контактная информация</h2>
    <div class="row gx-5 gy-4">
        <div class="col-md-4">
            <h3>Адрес</h3>
            <address>
                г. Москва, ул. Тверская, д. 12<br>
                Бизнес-центр "Тверской"
            </address>
        </div>
        <div class="col-md-4">
            <h3>Телефон</h3>
            <p><a href="tel:+74951234567">+7 (495) 123-45-67</a></p>
        </div>
        <div class="col-md-4">
            <h3>Электронная почта</h3>
            <p><a href="mailto:info@example.com">info@example.com</a></p>
        </div>
    </div>
</section>

<!-- Подвал -->
<footer class="container-fluid p-5 bg-dark text-light text-center">
    <p>© 2023 Кешбэк по картам. Все права защищены.</p>
</footer>

<script>
    // JavaScript для калькулятора экономии
    document.addEventListener('DOMContentLoaded', function() {
        const slider = document.getElementById('spending-slider');
        const currentSpendingEl = document.getElementById('current-spending');
        const savingsAmountEl = document.getElementById('savings-amount');
        const yearlySavingsEl = document.getElementById('yearly-savings');

        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        }

        function updateCalculator() {
            const spending = parseInt(slider.value);
            const monthlySavings = Math.round(spending * 0.02);
            const yearlySavings = monthlySavings * 12;

            currentSpendingEl.textContent = formatNumber(spending) + ' ₽';
            savingsAmountEl.textContent = formatNumber(monthlySavings) + ' ₽';
            yearlySavingsEl.textContent = formatNumber(yearlySavings) + ' ₽';

            // Добавляем анимацию к результату
            savingsAmountEl.classList.add('pulse');
            setTimeout(() => {
                savingsAmountEl.classList.remove('pulse');
            }, 300);
        }

        // Первоначальное обновление
        updateCalculator();

        // Обновление при изменении ползунка
        slider.addEventListener('input', updateCalculator);
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous">
</script>
</body>
</html>
