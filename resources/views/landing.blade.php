<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Кешбэк по картам</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
          integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65"
          rel="stylesheet" crossorigin="anonymous">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXKv4g05iyT9lQy"
        crossorigin="anonymous">
</script>
</body>
</html>
