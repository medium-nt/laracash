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
    function sendData() {
        Telegram.WebApp.sendData("Hello from Mini App!");
    }
</script>
</body>
</html>
