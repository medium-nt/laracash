<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="/public/images/logo_cashback.png">
    <link rel="apple-touch-startup-image" href="/public/images/logo_cashback.png">

    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Твой кешбек">

    <meta name="mobile-web-app-capable" content="yes">
    <link rel="shortcut icon" href="/public/images/logo_cashback.png">
    <link rel="icon" sizes="192x192" href="/public/images/logo_cashback.png">
    <link rel="icon" sizes="128x128" href="/public/images/logo_cashback.png">

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
    @livewire('search-component', [
            'user' => $user
    ])
</body>
</html>

