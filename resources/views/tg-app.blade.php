<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Telegram Mini App</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<h1>Привет из Mini App!</h1>
<button id="send">Отправить данные</button>

<script>
    // Инициализация Telegram WebApp
    const tg = window.Telegram.WebApp;
    tg.expand(); // разворачивает окно

    document.getElementById('send').addEventListener('click', () => {
        fetch('/api/telegram-data', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user: tg.initDataUnsafe.user,
                query_id: tg.initDataUnsafe.query_id
            })
        })
            .then(res => res.json())
            .then(data => {
                tg.showAlert("Данные отправлены: " + JSON.stringify(data));
            });
    });
</script>
</body>
</html>
