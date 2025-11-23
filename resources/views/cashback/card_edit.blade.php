@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-1 mb-2">
                            <a href="{{ route('cashback.index') }}" class="btn btn-default"><i class="fas fa-reply"></i></a>
                        </div>
                        <div class="col-md-11">
                            <input type="text" id="searchInput" class="form-control mb-3" placeholder="поиск категории">
                        </div>
                    </div>

                    <form action="{{ route('cashback.card_update', ['card' => $card->id]) }}" method="post">
                        @csrf
                        @method('PUT')

                        <button type="submit" class="btn btn-success mb-3">Сохранить</button>

                        <button type="button" class="btn btn-outline-secondary mb-3 ml-1" onclick="clearInputs()"><i class="fas fa-eraser"></i></button>

                        <button type="button" class="btn btn-outline-danger mb-3 ml-1" onclick="toggleEmptyInputs()" id="hideButton">
                            <i class="fas fa-eye" id="iconToggle"></i>
                        </button>

                        @php $allHidden = true @endphp
                        @foreach($cashbacks as $categoryId => $cashback)
                            @php
                                $display = '';
                                $percent = $cashback['percent'];
                                $categoryName = $cashback['category_name'];

                                if ($percent == '0.00' || $percent == '') {
                                    $percent = '';
                                    $display = 'none';
                                } else {
                                    $allHidden = false;
                                }

                                // ищем распознанное значение для этой категории
                                $recognizedItem = collect($recognizedCashback)
                                    ->firstWhere('category', $categoryName);

                                if ($recognizedItem) {
                                    $percent = $recognizedItem['cashback']; // перезаписываем процент
                                    $display = '';
                                }

                            @endphp

                            <div class="category mb-3 row"
                                 style="display: {{ $display }}"
                                 data-category="{{ $categoryName }}">
                                @php
                                    $count = mb_strlen($categoryName);
                                    if($count > 16) $px = '12px';
                                    else $px = '16px';
                                @endphp

                                <label class="col-sm-4 mt-2 text-center">
                                    <p style="font-size: {{ $px }}"><b>{{ $categoryName }}</b></p>
                                </label>

                                <input type="number" class="title form-control col-sm-2 mb-1 mr-md-3"
                                       min="0" max="100" step="0.5"
                                       name="categories[{{ $categoryId }}][percent]"
                                       value="{{ $percent }}" placeholder="%">

                                <input type="text" class="percent form-control col-sm-5"
                                       name="categories[{{ $categoryId }}][mcc]"
                                       value="{{ $cashback['mcc'] }}" placeholder="mcc">
                            </div>
                        @endforeach
                    </form>

                    @if($allHidden)
                        <script>
                            var divs = document.querySelectorAll('.category');
                            divs.forEach(function(div) {
                                var input = div.querySelector('input');
                                if (input && input.value === '') {
                                    icon = document.getElementById("iconToggle")
                                    div.style.display = '';
                                    icon.classList.remove("fa-eye");
                                    icon.classList.add("fa-eye-slash");
                                }
                            });
                        </script>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    @if($card->cashback_image)
                    <img src="{{ asset('storage/card_cashback_image/' . $card->cashback_image ?? '') }}"
                         alt="Картинка кешбэка" style="max-width: 50%; max-height: 50%;">

                        <form action="{{ route('cashback.delete_cashback_image', ['card' => $card->id]) }}" method="post">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger mt-2"
                                    onclick="return confirm('Вы уверены, что хотите удалить скриншот?')">
                                Удалить скриншот
                            </button>
                        </form>
                    <hr>
                    @endif

                    <form action="{{ route('cashback.download_cashback_image', ['card' => $card->id]) }}"
                          method="post" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="form-group">
                            <label for="image">Загрузить новый скриншот кешбэка</label>
                            <input type="file" class="form-control" id="image" name="image">
                        </div>
                        <button type="submit" class="btn btn-success">Загрузить</button>
                    </form>

                    <hr>
                    @if(!empty($recognizedCashback))
                        Распознанные категории и кешбэк:
                        <ul>
                            @foreach($recognizedCashback as $item)
                                <li>{{ $item['category'] }} — {{ $item['cashback'] }}%</li>
                            @endforeach
                        </ul>
                    @else
                        <a href="{{ route('cashback.recognize_cashback', ['card' => $card->id]) }}"
                           class="btn btn-sm btn-primary">
                            Распознать
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

{{-- Push extra scripts --}}

@push('js')
    <script>
        var isHiddenOneCard = true;

        const icon = document.getElementById("iconToggle");

        function toggleEmptyInputs() {
            var divs = document.querySelectorAll('.category');
            divs.forEach(function(div) {
                var input = div.querySelector('input');
                if (input && input.value === '') {
                    if (isHiddenOneCard) {
                        div.style.display = '';
                        icon.classList.remove("fa-eye");
                        icon.classList.add("fa-eye-slash");
                    } else {
                        div.style.display = 'none';
                        icon.classList.remove("fa-eye-slash");
                        icon.classList.add("fa-eye");
                    }
                }
            });

            // Переключаем состояние скрытия/показа
            isHiddenOneCard = !isHiddenOneCard;
        }

        function clearInputs() {
            $('input.title').val('');
            $('input.percent').val('');
        }

        // Получить ссылку на поле поиска
        var searchInput = document.getElementById('searchInput');

        // Добавить обработчик события при изменении значения поля поиска
        searchInput.addEventListener('input', function() {
            // Получить все элементы <div class="mb-3 row">
            var divs = document.querySelectorAll('.mb-3.row');

            // Получить значение поисковой строки
            var searchValue = searchInput.value.toLowerCase();

            // Пройтись по каждому элементу и скрыть или показать его в зависимости от соответствия поисковой строки
            divs.forEach(function(div) {
                var label = div.querySelector('label').textContent.toLowerCase();
                if (label.includes(searchValue)) {
                    div.style.display = '';
                } else {
                    div.style.display = 'none';
                }
            });
        });

        function autofillCategories(recognized) {
            recognized.forEach(item => {
                // ищем блок по названию категории
                const $row = $(`.category[data-category="${item.category}"]`);
                if ($row.length) {
                    // заполняем процент
                    $row.find('input[name*="[percent]"]').val(item.percent);
                    // заполняем MCC
                    if (item.mcc) {
                        $row.find('input[name*="[mcc]"]').val(item.mcc);
                    }
                    // если блок был скрыт, показываем
                    $row.show();
                } else {
                    console.warn(`Категория "${item.category}" не найдена`);
                }
            });
        }
    </script>
@endpush
