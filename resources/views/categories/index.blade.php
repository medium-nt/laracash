@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">

                <a href="{{ route('categories.create') }}" class="btn btn-primary mb-3">Добавить Категорию</a>

                @if($categories->count() === 0)
                    <a href="{{route('categories.fill_default')}}" class="btn btn-outline-success mb-3 ml-1">Заполнить категориями по-умолчанию</a>

                    <div class="alert alert-warning" role="alert">
                        Вы можете заполнить стандартными категориями, нажав на кнопку "Заполнить категориями по-умолчанию"
                    </div>
                @endif

                <table id="categories" class="table table-hover table-bordered">
                    <thead class="thead-dark">
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Категория</th>
                        <th scope="col">Ключевые слова для поиска</th>
                        <th scope="col"></th>
                    </tr>
                    </thead>
                    <tbody>

                    @foreach($categories as $category)
                        <tr>
                            <th style="width: 50px"
                                class="edit_category {{$category->id}}"
                                data-toggle='modal' data-target='#modal'
                                scope="row">{{$loop->iteration}}</th>
                            <td class="edit_category {{$category->id}}"
                                data-toggle='modal'
                                data-target='#modal'>{{$category->title}}</td>
                            <td class="edit_category {{$category->id}}"
                                data-toggle='modal'
                                data-target='#modal'>{{$category->keywords}}</td>

                            <td style="width: 40px">
                                <div class="btn-group" role="group">
                                    <a href="{{ route('categories.edit', ['category' => $category->id]) }}" class="btn btn-primary mr-1">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('categories.destroy', ['category' => $category->id]) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Вы уверены?')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

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
