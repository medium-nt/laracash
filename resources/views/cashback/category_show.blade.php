@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <a href="{{ url()->previous() }}" class="btn btn-default"><i class="fas fa-reply"></i></a>

                <strong class="ml-3"> Ваши карты с кешбеком по категории "{{ $category->title }}":</strong>

                <div class="table-responsive col-xl-6 col-lg-6 col-md-12">
                    <table class="table table-hover table-bordered mt-3">
                        <thead class="thead-dark">
                        <tr>
                            <th scope="col">Карта</th>
                            <th scope="col">Процент кешбека</th>
                            <th scope="col">MCC</th>
                        </tr>
                        </thead>
                        <tbody>

                        @php
                            $date = '0000-00-00 00:00:00';
                            $cards = $category->cards()->get()
                        @endphp

                        @if($cards->isEmpty())
                            <tr>
                                <td colspan="3" class="text-center">
                                    У вас нет карт c такой категорией кешбека
                                </td>
                            </tr>
                        @else
                            @foreach($cards as $card)
                                @continue($card->pivot->cashback_percentage == 0)
                                <tr>
                                    <th scope="row">{{ $card->number }} ({{ $card->bank->title }})</th>
                                    <td>{{ $card->pivot->cashback_percentage }}%</td>
                                    <td>{{ $card->pivot->mcc }}</td>
                                </tr>
                                @php
                                    if ($date < $card->pivot->updated_at) {
                                        $date = $card->pivot->updated_at;
                                    }
                                @endphp
                            @endforeach
                        @endif

                        </tbody>
                    </table>

                    @php
                        $dateFormat = ($date != '0000-00-00 00:00:00') ? now()->parse($date)->format('d/m/Y H:i') : 'Нет данных';
                    @endphp

                    <small>Дата актуальности кешбека: <b>{{ $dateFormat }}</b></small>
                </div>

            </div>
        </div>
    </div>
@stop
