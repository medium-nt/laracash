@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
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

                <form action="{{ route('categories.store') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label for="title">Название категории</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ old('title') }}" required>
                    </div>

                    <div class="form-group">
                        <label for="keywords">Ключевые слова для поиска (необязательно)</label>
                        <input type="text" class="form-control" id="keywords" name="keywords" value="{{ old('keywords') }}">
                    </div>

                    <button type="submit" class="btn btn-primary">Добавить</button>
                </form>

                <script>
                    $(document).ready(function() {
                        $('#categories').DataTable({
                            'pagingType': "simple_numbers",
                            'paging': true,
                            'lengthChange': true,
                            'searching': true,
                            'ordering': false,
                            'info': false,
                            'autoWidth': false,
                            "iDisplayLength": 100,
                            "language": {
                                "emptyTable": "Нет данных для отображения в таблице",
                                "infoPostFix": "",
                                "thousands": ",",
                                "loadingRecords": "Загрузка...",
                                "processing": "Обработка...",
                                "zeroRecords": "Не найдено совпадающих записей",
                                "aria": {
                                    "sortAscending": ": активировать для сортировки столбца по возрастанию",
                                    "sortDescending": ": активировать для сортировки столбца по убыванию"
                                },
                                "infoFiltered":   "(Отфильтровано _MAX_ записей)",
                                "info": "Показано с _START_ по _END_ записей из _TOTAL_",
                                "lengthMenu": "Показывать _MENU_ записей на странице",
                                "infoEmpty": "Нет записей.",
                                "search": "Поиск:",
                                "paginate": {
                                    "first": "Первая",
                                    "previous": "Предыдущая",
                                    "last": "Последняя",
                                    "next": "Следующая"
                                }
                            }
                        })
                    });

                </script>

            </div>
        </div>
    </div>
@stop
