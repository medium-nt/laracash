@extends('layouts.app')

{{-- Customize layout sections --}}

@section('subtitle', $title)
@section('content_header_title', $title)

{{-- Content body: main page content --}}

@section('content_body')
    <div class="col-md-12">
        @if($cashbackTable == null)
            <div class="alert alert-danger">
                Сначала добавьте Карты и Категории.
            </div>
        @else
            <div class="alert alert-default-success">
                <div class="ml-3">
                    <li>Для каждой карты укажите все категории кешбека на этот месяц, которые вы хотите выбрать. Для этого нажимайте на соответствующие ячейки таблицы.</li>
                    <li>Для каждой карты укажите количество категорий кешбека, которые можно выбрать.</li>
                    <li>Если какие-то категории вы уже выбрали, то закрепите их нажатием на соответствующую иконку в ячейке таблицы.</li>
                    <li>Отметьте категории, которые являются важными для вас. Их система будет выбирать в первую очередь.</li>
                    <li>После того как вы закончите, нажмите кнопку "Оптимизировать".</li>
                    <li>Система выберет категории кешбека для каждой карты так, чтобы они не пересекались и при этом были выбраны максимальные по размеру значения, исходя из ваших важных категорий.</li>
                    <li>Все выбранные категории кешбека будут отмечены зеленой заливкой.</li>
                    <li>Если вас устраивает результат распределения кешбека, то нажмите кнопку "Перенести в таблицу кешбека" и данные будут сохранены в вашей таблице кешбэка на месяц.</li>
                </div>
            </div>

            <div class="card">
                <div class="card-body table-container">
                    <table id="cashback" class="table table-hover table-bordered align-middle table-col-auto">
                        <thead>
                            <tr>
                                @php
                                    $width = 100 / (count($allCardUser) + 1);
                                @endphp
                                <td style="width: {{ $width }}%"></td>

                                @foreach($allCardUser as $card)
                                    <th class="text-center card-number-header" style="width: {{ $width }}%">
                                        {{ $card->number }} <br> ({{ $card->bank->title }})
                                    </th>
                                @endforeach
                            </tr>
                            <tr>
                                @php
                                    $width = 100 / (count($allCardUser) + 1);
                                @endphp
                                <td style="width: {{ $width }}%">Количество категорий</td>

                                @foreach($allCardUser as $card)
                                    <th class="text-center number_editable"
                                        data-card-id="{{ $card->id }}"
                                        style="width: {{ $width }}%">
                                        {{ $card->number_categories }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($cashbackTable as $categoryId => $cashback)
                            <tr>
                                <th scope="row"
                                    class="category-name-header important_editable"
                                    data-category-id="{{ $categoryId }}"
                                    @if($cashback['is_important']) style="color: orange;" @endif>
                                    {{ $cashback['category_name'] }}
                                    @if($cashback['is_important'])
                                        <i class="fa fa-star star-icon" style="color: orange" title="Важная категория"></i>
                                    @endif
                                </th>
                                @foreach($cashback as $card)
                                    @if(isset($card['percent']))
                                        <td class="text-center editable"
                                            data-is-check="{{ $card['is_check'] }}"
                                            data-card-id="{{ $card['card_id'] }}"
                                            data-category-id="{{ $categoryId }}">
                                            @if ($card['percent'] == '-' || $card['percent'] == 0)
                                                -
                                            @else
                                                <b>{{ $card['percent'] }} %</b>
                                            @endif
                                            <i class="ml-2 fa fa-thumbtack pin-icon {{ $card['is_check'] ? 'text-primary' : 'text-muted' }}"
                                               style="cursor:pointer"
                                               title="{{ $card['is_check'] ? 'Закреплено' : 'Не закреплено' }}"></i>
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@stop

@push('css')
    {{-- Add here extra stylesheets --}}
    <style>
        .table-container {
            overflow-x: auto; /* Добавляем горизонтальную прокрутку */
            width: 100%;      /* Устанавливаем ширину контейнера */
        }

        .table-container table {
            min-width: 100%; /* Минимальная ширина таблицы равна ширине контейнера */
        }

        .card-number-header {
            background-color: #f7f7f7;
            font-weight: bold;
            text-align: center;
        }

        .category-name-header {
            background-color: #f7f7f7;
            font-weight: bold;
            vertical-align: middle;
        }

        .pin-icon {
            font-size: 12px;
            margin-right: 5px;
            opacity: 0.6;
            transition: all 0.2s ease;
        }

        .pin-icon.text-primary {
            color: #ff6b35 !important; /* оранжевый цвет */
            opacity: 1;
        }

        .pin-icon:hover {
            opacity: 1 !important;
            transform: scale(1.2);
        }
    </style>
@endpush

@push('js')

    <script>
        // универсальная подсветка результата
        function highlight(cell, ok) {
            cell.style.backgroundColor = ok ? '#d4edda' : '#f8d7da';
            if (ok) setTimeout(() => cell.style.backgroundColor = '', 1000);
        }

        // универсальный inline‑редактор
        function makeEditable(selector, saveFn, suffix = '') {
            document.querySelectorAll(selector).forEach(cell => {
                cell.addEventListener('click', e => {
                    if (e.target.classList.contains('pin-icon')) return;
                    if (cell.querySelector('input')) return;

                    let value = cell.innerText.replace('%','').trim();
                    let input = document.createElement('input');
                    input.type = 'number';
                    input.value = value === '-' ? '' : value;
                    input.classList.add('form-control','form-control-sm');
                    input.style.width = '80px';

                    cell.innerHTML = '';
                    cell.appendChild(input);
                    input.focus();

                    input.addEventListener('blur', () => saveFn(cell, input.value, suffix));
                    input.addEventListener('keydown', e => { if (e.key === 'Enter') input.blur(); });
                });
            });
        }

        // сохранение "важности"
        function saveImportant(cell) {
            fetch('/categories/change_important', {
                method:'POST',
                headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:JSON.stringify({category_id:cell.dataset.categoryId})
            })
                .then(r=>r.json())
                .then(data=>{
                    if(data.status==='ok'){
                        if(data.is_important){
                            cell.style.color='orange';
                            if(!cell.querySelector('.star-icon'))
                                cell.insertAdjacentHTML('beforeend','<i class="fa fa-star star-icon" style="color:orange" title="Важная категория"></i>');
                        } else {
                            cell.style.color='';
                            cell.querySelector('.star-icon')?.remove();
                        }
                        highlight(cell,true);
                    } else highlight(cell,false);
                })
                .catch(()=>highlight(cell,false));
        }

        // сохранение числа категорий
        function saveNumberValue(cell,value){
            fetch('/cards/number_update',{
                method:'POST',
                headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:JSON.stringify({card_id:cell.dataset.cardId,number_categories:value})
            })
                .then(r=>r.json())
                .then(data=>{
                    if(data.status==='ok'){ cell.innerHTML=value?`<b>${value}</b>`:'0'; highlight(cell,true); }
                    else highlight(cell,false);
                })
                .catch(()=>highlight(cell,false));
        }

        // сохранение процента кешбэка
        function saveValue(cell,value){
            fetch('/cashback/inline-update',{
                method:'POST',
                headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:JSON.stringify({card_id:cell.dataset.cardId,category_id:cell.dataset.categoryId,percent:value})
            })
                .then(r=>r.json())
                .then(data=>{
                    if(data.status==='ok'){
                        // Сохраняем текущее состояние гвоздика
                        let currentIsCheck = cell.dataset.isCheck;
                        let pinIconClass = currentIsCheck === '1' ? 'text-primary' : 'text-muted';
                        let pinTitle = currentIsCheck === '1' ? 'Закреплено' : 'Не закреплено';

                        cell.innerHTML=value?`<b>${value} %</b>`:'-';
                        cell.insertAdjacentHTML('beforeend',`<i class="ml-2 fa fa-thumbtack pin-icon ${pinIconClass}" style="cursor:pointer" title="${pinTitle}"></i>`);
                        attachPinHandler(cell.querySelector('.pin-icon'));
                        highlight(cell,true);
                    } else highlight(cell,false);
                })
                .catch(()=>highlight(cell,false));
        }

        // обработка клика по гвоздику
        function attachPinHandler(pin){
            pin.addEventListener('click',function(e){
                e.stopPropagation();
                let cell=this.closest('td');
                let {cardId,categoryId,isCheck}=cell.dataset;
                fetch('/cashback/toggle-pin',{
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                    body:JSON.stringify({card_id:cardId,category_id:categoryId,is_check:isCheck!=='1'})
                })
                    .then(r=>r.json())
                    .then(data=>{
                        if(data.status==='ok'){
                            cell.dataset.isCheck=data.is_check?'1':'0';
                            this.classList.toggle('text-primary',data.is_check);
                            this.classList.toggle('text-muted',!data.is_check);
                            this.title=data.is_check?'Закреплено':'Не закреплено';
                        }
                    });
            });
        }

        // инициализация
        document.querySelectorAll('.important_editable').forEach(cell=>cell.addEventListener('click',()=>saveImportant(cell)));
        makeEditable('.number_editable',saveNumberValue);
        makeEditable('.editable',saveValue,'%');
        document.querySelectorAll('.pin-icon').forEach(pin=>attachPinHandler(pin));
    </script>

@endpush
