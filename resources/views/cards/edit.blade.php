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

                <form action="{{ route('cards.update', ['card' => $card->id]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label for="number">Номер карты</label>
                        <input type="text" class="form-control" id="number" name="number" value="{{ $card->number }}" required>
                    </div>

                    <div class="form-group">
                        <label for="bank_id">Банк</label>
                        <select class="form-control" id="bank_id" name="bank_id" required>
                            @foreach($banks as $bank)
                                <option value="{{$bank->id}}" {{ $card->bank_id == $bank->id ? 'selected' : '' }}>{{$bank->title}}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="color">Цвет карты</label>
                        <input type="color" class="form-control" id="color" name="color" value="{{ $card->color }}" required>
                    </div>

                    <button type="submit" class="btn btn-success">Сохранить</button>
                </form>

            </div>
        </div>
    </div>
@stop
