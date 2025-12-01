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
    @livewire('search-component', [
            'user' => $user
    ])

<script>
    document.addEventListener('DOMContentLoaded', function () {
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

</script>
</body>
</html>

