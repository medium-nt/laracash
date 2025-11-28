<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>My Mini App</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<h1>Привет из Mini App!</h1>
<button onclick="sendData()">Отправить данные боту</button>

<script>
    Telegram.WebApp.ready();

    function sendData() {
        const data = "Hello from Mini App!";

        // Сначала отправляем на сервер через fetch
        fetch('api/mini-app-data', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}' // если CSRF включён
            },
            body: JSON.stringify({ data })
        })
            .then(res => res.json())
            .then(res => console.log('Server response:', res))
            .catch(err => console.error(err));

        // Можно также отправить данные боту через Telegram, если нужно
        Telegram.WebApp.sendData(data);
    }
</script>
</body>
</html>
