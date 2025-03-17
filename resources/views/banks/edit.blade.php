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

                <form action="{{ route('banks.update', ['bank' => $bank->id]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label for="title">Название банка</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ $bank->title }}" required>
                    </div>

                    <button type="submit" class="btn btn-success">Сохранить</button>
                </form>

            </div>
        </div>
    </div>
@stop
