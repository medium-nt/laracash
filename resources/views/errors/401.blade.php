<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка | Неверный код клиента!</title>
</head>
<body>
    <p>{{ $exception->getMessage() }}</p>
    <a href="{{ route('login') }}" class="btn btn-success">
        Войти в систему
    </a>
</body>
</html>
