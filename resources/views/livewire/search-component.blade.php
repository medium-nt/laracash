<div class="container">
    <div class="row">
        <div class="col-12">
            <div class="search-form">
                <div class="input-group">
                    <a href="{{ route('search.index', ['token' => $user->search_token]) }}"
                       id="back-btn"
                       class="btn btn-default btn-l mr-0"
                    >
                        <i class="fas fa-angle-double-left"></i>
                    </a>

                    <input class="form-control" wire:model.live="search" type="text" name="searchInput" id="searchInput"
                           aria-describedby="search-btn" placeholder="поиск по категории..." autofocus>
                    <label for="search" class="sr-only">Search</label>

                    <a href="/login" class="btn btn-default btn-r ml-0"><i class="fas fa-sign-in-alt"></i></a>
                </div>
                <div wire:loading class="loader">
                    <span>Загрузка...</span>
                </div>
            </div>

            @if (count($filteredCategoriesCashback) == 0)
                <div class="category alert alert-warning" role="alert">
                    По вашему запросу ничего не найдено.
                </div>
            @endif

            @isset($filteredCategoriesCashback)
                @php
                    $date = '1999-01-01 00:00:00';
                @endphp

                @foreach($filteredCategoriesCashback as $category => $cardList)
                    <div class="category mt-5">
                        <h5>{{ $category }}</h5>
                    </div>

                    <table class="topics-table">
                        <tbody>
                        @isset($cardList)
                            @foreach($cardList as $card)
                                <tr class="topic-item-1">
                                    <td>
                                        <span class="badge"
                                              style="background-color: {{$card->card_color}}; color: white;"
                                              data-target="#cashbackModal"
                                              data-card-id="{{ $card->card_id }}"
                                              data-cashback-image="{{ $card->cashback_image }}"
                                              data-toggle="modal">
                                            {{ $card->card_number }} {{ $card->bank_title }}
                                        </span>

                                        @if($card->mcc != '')
                                            <i class="mcc {{$card->id}} fas fa-exclamation-circle"
                                               style="color: #007bff;"
                                               data-toggle='modal'
                                               data-target='#modal'
                                               data-mcc='{{ $card->mcc }}'
                                            ></i>
                                        @endif
                                    </td>
                                    <td style="width: 100px">{{ $card->cashback_percentage }}%</td>
                                </tr>

                                @php
                                    if ($date < $card->updated_at) {
                                        $date = $card->updated_at;
                                    }
                                @endphp
                            @endforeach
                        @endisset
                        </tbody>
                    </table>
                @endforeach

                <div class="category mb-5">
                    @if(!isset($card->cashback_percentage))
{{--                        У вас нет карт с такой категорией кешбека--}}
                    @else
                        @php
                            $dateFormat = ($date != '0000-00-00 00:00:00') ? now()->parse($date)->format('d/m/Y') : 'Нет данных';
                        @endphp
                        <br>
                        <small>Дата актуальности кешбека: <b>{{ $dateFormat }}</b></small>
                    @endif
                </div>
            @endisset
        </div>
    </div>

    <!-- Модальное окно -->
    <div class="modal fade" id="cashbackModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content bg-white">
                <!-- Кнопка закрытия -->
                <div class="modal-header border-0">
                    <button type="button" class="close text-danger" data-dismiss="modal" aria-label="Закрыть">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <!-- Тело модалки -->
                <div class="modal-body text-center">
                    <img id="modalImage" src="" alt="Скриншот кешбэка" style="max-width: 100%; height: auto;">
                </div>
            </div>
        </div>
    </div>

    <script>
        $('#cashbackModal').on('show.bs.modal', function (event) {
            var trigger = $(event.relatedTarget);
            var cardId = trigger.data('card-id');
            var src = trigger.data('cashback-image');
            var imageSrc = '/storage/card_cashback_image/' + src;

            var modal = $(this);
            if (src === '') {
                imageSrc = '';
                modal.find('#modalImage').attr('alt', 'Скриншот карты не найден');
                modal.find('#modalImage').attr('src', imageSrc);
            } else {
                modal.find('#modalCardId').text('ID карты: ' + cardId);
                modal.find('#modalImage').attr('src', imageSrc);
            }
        });
    </script>

</div>
