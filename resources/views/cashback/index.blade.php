@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-12">
        @if($cashbackTable == null)
            <div class="alert alert-danger">
                Сначала добавьте Карты и Категории.
            </div>
        @else
            <div class="card">
                <div class="card-body table-container">

                    <a class="btn btn-outline-secondary mb-3"
                       href="#"
                       id="toggleButton"
                       onclick="toggleTableRows()">
                        Показать все категории
                    </a>

{{--                    <a href="{{ route('cashback.all_available_cashback') }}"--}}
{{--                       class="btn btn-link mb-3 ml-3"--}}
{{--                       id="toggleButton">--}}
{{--                        Подобрать оптимальный кешбек--}}
{{--                    </a>--}}

                    <table id="cashback" class="table table-hover table-bordered align-middle table-col-auto">
                        <thead>
                            <tr>
                                @php
                                    $width = 100 / (count($allCardUser) + 1);
                                @endphp
                                <td style="width: {{ $width }}%"></td>

                                @foreach($allCardUser as $card)
                                    <th class="text-center card-number-header" style="width: {{ $width }}%">
                                        <a href="{{ route('cashback.card_edit', ['card' => $card->id]) }}">
                                            {{ $card->number }} <br> ({{ $card->bank->title }})
                                        </a>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($cashbackTable as $categoryId => $cashback)
                            <tr>
                                <th scope="row" class="category-name-header">
                                    <a href="{{ route('cashback.category_show', ['category' => $categoryId]) }}">
                                        {{ $cashback['category_name'] }}
                                    </a>
                                </th>
                                @foreach($cashback as $card)
                                    @if(isset($card['percent']))
                                        <td class="text-center">
                                            @if ($card['percent'] == '-' || $card['percent'] == 0)
                                                -
                                            @else
                                                <b>{{ $card['percent'] }} %  </b>
                                                @if($card['mcc'] != '')
                                                <small class="d-block d-sm-inline">
                                                    <i>{{ $card['mcc'] }}</i>
                                                </small>
                                                @endif
                                            @endif
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@stop

{{-- Push extra CSS --}}

@push('css')
    {{-- Add here extra stylesheets --}}
    <style>
        .table-container {
            overflow-x: auto; /* Добавляем горизонтальную прокрутку */
            width: 100%;      /* Устанавливаем ширину контейнера */
        }

        .table-container table {
            min-width: 100%; /* Минимальная ширина таблицы равна ширине контейнера */
        }

        .card-number-header {
            background-color: #f7f7f7;
            font-weight: bold;
            text-align: center;
        }

        .category-name-header {
            background-color: #f7f7f7;
            font-weight: bold;
            vertical-align: middle;
        }
    </style>
@endpush

{{-- Push extra scripts --}}

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('#cashback tbody tr');

            tableRows.forEach(function(row) {
                const cells = row.querySelectorAll('td:not(:first-child)');
                let hideRow = true;

                cells.forEach(function(cell) {
                    if (cell.textContent.trim() !== '-') {
                        hideRow = false;
                    }
                });

                if (hideRow) {
                    row.style.display = 'none';
                }
            });
        });

        let isHidden = true;

        function toggleTableRows() {
            const tableRows = document.querySelectorAll('#cashback tbody tr');

            tableRows.forEach(function(row) {
                const cells = row.querySelectorAll('td:not(:first-child)');
                let hideRow = true;

                cells.forEach(function(cell) {
                    if (cell.textContent.trim() !== '-') {
                        hideRow = false;
                    }
                });

                if (hideRow) {
                    if (isHidden) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });

            isHidden = !isHidden; // Переключаем состояние скрытия/показа
            updateToggleButtonText();
        }

        function updateToggleButtonText() {
            const toggleButton = document.getElementById('toggleButton');
            toggleButton.textContent = isHidden ? 'Показать все категории' : 'Скрыть пустые категории';
        }
    </script>
@endpush
