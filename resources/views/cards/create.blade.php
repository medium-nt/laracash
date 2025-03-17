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

                <form action="{{ route('cards.store') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label for="number">Название карты или номер</label>
                        <input type="text" class="form-control" id="number" name="number" value="{{ old('title') }}" required>
                    </div>

                    <div class="form-group">
                        <label for="bank_id">Банк</label>
                        <select class="form-control" id="bank_id" name="bank_id" required>
                            @foreach($banks as $bank)
                                <option value="{{$bank->id}}">{{$bank->title}}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="color">Цвет карты</label>
                        <input type="color" class="form-control" id="color" name="color" value="#C0C0C0" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Добавить</button>
                </form>

            </div>
        </div>
    </div>
@stop
