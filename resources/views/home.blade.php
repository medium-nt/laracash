@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', 'Welcome')
@section('content_header_title', 'Welcome')

{{-- Content body: main page content --}}

@section('content_body')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Home</h3>
        </div>

        <div class="card-body">
            Стартовая страница
        </div>

    </div>
@stop
