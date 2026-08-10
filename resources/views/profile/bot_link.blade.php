@extends('layouts.app')

@section('subtitle', 'Привязка Telegram')
@section('content_header_title', 'Привязка Telegram')

@section('content_body')
    <div class="col-md-6">
        <div class="card">
            @if(session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card-body">
                @if(empty($tg))
                    <p class="text-danger">Некорректная ссылка привязки.</p>
                @else
                    <p>Привязать Telegram (ID: {{ $tg }}) к вашему аккаунту LaraCash?</p>

                    <form method="POST" action="{{ route('profile.bot_link.store') }}">
                        @csrf
                        <input type="hidden" name="telegram_id" value="{{ $tg }}">
                        <button type="submit" class="btn btn-primary">Привязать</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@stop
