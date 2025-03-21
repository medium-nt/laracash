@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <table class="table table-hover table-bordered">
                    <thead class="thead-dark">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Имя</th>
                            <th scope="col">email</th>
                            <th scope="col">Создан</th>
                            <th scope="col">Обновлен</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>{{ $user->id }}</td>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>{{ now()->parse($user->created_at)->format('d/m/Y H:i') }}</td>
                                <td>{{ now()->parse($user->updated_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <div class="btn-group" role="group">
                                    <a href="{{ route('users.edit', ['user' => $user->id]) }}" class="btn btn-primary mr-1">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                        <form action="{{ route('users.destroy', ['user' => $user->id]) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger">
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
                <x-pagination-component :collection="$users" />

            </div>
        </div>
    </div>
@stop

{{-- Push extra CSS --}}

@push('css')
    {{-- Add here extra stylesheets --}}
    {{-- <link rel="stylesheet" href="/css/admin_custom.css"> --}}
@endpush

{{-- Push extra scripts --}}

@push('js')
{{--    <script> console.log("Hi, I'm using the Laravel-AdminLTE package!"); </script>--}}
@endpush
