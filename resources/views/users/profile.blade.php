@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

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

            <form action="{{ route('profile.update') }}" method="POST">
                @method('PUT')
                @csrf
                <div class="card-body">
                    <div class="form-group">
                        <label for="name">Имя</label>
                        <input type="text" class="form-control" id="name" value="{{ $user->name }}"
                               name="name" placeholder="Имя" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" id="email" value="{{ $user->email }}"
                               name="email" placeholder="Email" required>
                    </div>

                    <div class="form-group">
                        <label for="search_token">Персональная ссылка</label>
                        @if($user->search_token)
                            <a href=" {{ route('search.index', ['token' => $user->search_token]) }}"
                               target="_blank"><small class="ml-1"><u>перейти</u></small></a>
                        @endif
                        <input type="text" class="form-control" id="search_token" value="{{ $user->search_token }}"
                               name="search_token" placeholder="" readonly>
                        <a href="{{ route('profile.generate_search_token') }}" class="generate_code"
                           onclick="return confirm('Вы уверены, что хотите сгенерировать новый код персональной ссылки? Обратите внимание, что ссылки со старым кодом станут нерабочими.')">
                            <small class="ml-1"><u>сгенерировать новую</u></small>
                        </a>
                    </div>

                    <div class="form-group">
                        <label for="password">Новый пароль</label>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Пароль">
                    </div>

                    <div class="form-group">
                        <label for="password_confirmation">Подтверждение пароля</label>
                        <input type="password" class="form-control" id="password_confirmation"
                               name="password_confirmation" placeholder="Подтверждение пароля">
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@stop
