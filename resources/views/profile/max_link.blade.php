@extends('layouts.app')

@section('subtitle', 'Привязка MAX')
@section('content_header_title', 'Привязка MAX')

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
                @if(empty($maxId))
                    <p class="text-danger">Некорректная ссылка привязки.</p>
                @else
                    <p>Привязать MAX (ID: {{ $maxId }}) к вашему аккаунту {{ config('app.name') }}?</p>

                    <form method="POST" action="{{ route('profile.max_link.store') }}">
                        @csrf
                        <input type="hidden" name="max_id" value="{{ $maxId }}">
                        <button type="submit" class="btn btn-primary">Привязать</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@stop
