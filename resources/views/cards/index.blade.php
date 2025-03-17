@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">

                <a href="{{ route('cards.create') }}" class="btn btn-primary mb-3">Добавить Карту</a>

                <table class="table table-hover table-bordered">
                    <thead class="thead-dark">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Номер карты</th>
                            <th scope="col">Название банка</th>
                            <th scope="col">Цвет</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cards as $card)
                            <tr>
                                <td>{{ $card->id }}</td>
                                <td>{{ $card->number }}</td>
                                <td>{{ $card->bank->title }}</td>
                                <td><div style="width: 20px; height: 20px; background-color: {{$card->color}};"></div></td>
                                <td>
                                    <div class="btn-group" role="group">
                                    <a href="{{ route('cards.edit', ['card' => $card->id]) }}" class="btn btn-primary mr-1">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                        <form action="{{ route('cards.destroy', ['card' => $card->id]) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Вы уверены? Так же удалятся все кешбеки по этой карте.')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Pagination --}}
                <x-pagination-component :collection="$cards" />

            </div>
        </div>
    </div>
@stop
