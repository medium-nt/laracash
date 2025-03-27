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

    <!-- AdminLTE -->
    <link href="{{ asset('/vendor/adminlte/dist/css/adminlte.min.css') }}" rel="stylesheet">

    <!-- jQuery -->
    <script src="{{ asset('/vendor/jquery/jquery.min.js') }}"></script>

    <!-- Bootstrap -->
    <script src="{{ asset('/vendor/bootstrap/js/bootstrap.min.js') }}"></script>

    <!-- Global Search -->
{{--    <link rel="stylesheet" href="/public/css/globalSearch.css">--}}
{{--    <script src="/public/js/globalSearch.js?v=1"></script>--}}

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="{{ asset('/vendor/fontawesome-free/css/all.min.css') }}">

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
<div class="container">
    <div class="row">
        <div class="col-12">
            <div class="search-form">
                <div class="input-group">

                    <a href="{{ route('search.index', ['token' => $token]) }}"
                       id="back-btn"
                       class="btn btn-default btn-l mr-0"
                    >
                        <i class="fas fa-angle-double-left"></i>
                    </a>

                    <input class="form-control" type="text" name="search" id="search"
                           aria-describedby="search-btn" placeholder="поиск по категории..." autofocus>
                    <label for="search" class="sr-only">Search</label>

                    <a href="/login" class="btn btn-default btn-r ml-0"><i class="fas fa-sign-in-alt"></i></a>

                    <div id="search_box-result"></div>
                </div>
            </div>

{{--            <script src="/public/js/modal.js?v=<?=time()?>"></script>--}}

            @isset($categoriesCashback)
                @php
                    $date = '1999-01-01 00:00:00';
                @endphp

                @foreach($categoriesCashback as $category => $cardList)
                    <div class="category mt-5">
                        <h5>{{ $category }}</h5>
                    </div>

                    <table class="topics-table">
                        <tbody>
                        @isset($cardList)
                            @foreach($cardList as $card)
                                <tr class="topic-item-1">
                                    <td>
                                        <span class="badge" style="background-color: {{$card->card_color}}; color: white;">
                                            {{ $card->card_number }} {{ $card->bank_title }}
                                        </span>

                                        @if($card->mcc != '')
                                        <i class="mcc {{$card->id}} fas fa-exclamation-circle" style="color: #007bff;"
                                           data-toggle='modal' data-target='#modal'></i>
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
                        У вас нет карт с такой категорией кешбека
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
</div>
</body>
</html>

