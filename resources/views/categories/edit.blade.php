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

                <form action="{{ route('categories.update', ['category' => $category->id]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label for="title">Название категории</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ $category->title }}" required>
                    </div>

                    <div class="form-group">
                        <label for="keywords">Ключевые слова для поиска (необязательно)</label>
                        <input type="text" class="form-control" id="keywords" name="keywords" value="{{ $category->keywords }}">
                    </div>

                    <button type="submit" class="btn btn-success">Сохранить</button>
                </form>

            </div>
        </div>
    </div>
@stop
