@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-12">
        @if($banks_is_empty)
            <div class="alert alert-danger">
                Сначала добавьте хотя бы один Банк.
            </div>
        @else
        <div class="card">
            <div class="card-body">

                <a href="{{ route('cards.create') }}" class="btn btn-primary mb-3">Добавить Карту</a>

                <div class="table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead class="thead-dark">
                            <tr>
                                <th scope="col">Номер карты</th>
                                <th scope="col">Название банка</th>
                                <th scope="col">Цвет</th>
                                <th scope="col">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cards as $card)
                                <tr>
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
                </div>

                {{-- Pagination --}}
                <x-pagination-component :collection="$cards" />

            </div>
        </div>
        @endif
    </div>
@stop
