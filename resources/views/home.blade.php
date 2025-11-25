@extends('layouts.app')

@section('subtitle', 'Welcome')
@section('content_header_title', 'Welcome')

@section('content_body')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title"><b>Как пользоваться сайтом?</b></h5>
        </div>
        <div class="card-body">
            <p>Наш сайт позволяет удобно хранить и показывать информацию о кешбеке по всем вашим картам.</p>
            <p>Для удобства вам будет сгенерирована персональная страница, которая позволит без входа в Личный Кабинет
                проверять категории текущего кешбека.</p>
            <p>Входить в Личный кабиент потребуется только когда вам необходимо будет сменить категории кешбека на следующий месяц.</p>
        </div>
    </div>

    <div class="col-12" id="faq">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><b>Чтобы произвести первичную настройку, вам необходимо выполнить несколько простых шагов:</b></h5>
            </div>
        </div>

        <div class='card'>
            <a class='d-block w-100 faq_question_link collapsed' data-toggle='collapse' href='#answer1' aria-expanded='false'>
                <div class='card-header'>
                    <h4 class='card-title w-100 position-relative'>
                        <div class='faq_text faq_question_text'>Первый шаг</div>
                    </h4>
                </div>
            </a>
            <div id='answer1' class='collapse' data-parent='#faq'>
                <div class='card-body'>
                    <div class='faq_text'>
                        <p>1) После входа в Личный Кабинет перейдите по очереди в разделы <a href="/banks">"Банки"</a>
                            и <a href="/categories">"Категории"</a> и добавьте название ваших банков и нужные вам категории кешбека.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class='card'>
            <a class='d-block w-100 faq_question_link collapsed' data-toggle='collapse' href='#answer2' aria-expanded='false'>
                <div class='card-header'>
                    <h4 class='card-title w-100 position-relative'>
                        <div class='faq_text faq_question_text'>Второй шаг</div>
                    </h4>
                </div>
            </a>
            <div id='answer2' class='collapse' data-parent='#faq'>
                <div class='card-body'>
                    <div class='faq_text'>
                        <p>2) Далее перейдите в раздел <a href="/cards">"Ваши карты"</a> и добавьте ваши карты с кешбеком.</p>

                        <p>При добавлении карты вы можете указывать как ее название, так и часть ее номера (например, последние 4 цифры). </p>
                        <p>Данное действие нужно лишь для того, чтобы вы могли различать карты, если у вас несколько карт одного банка.</p>
                        <p><b>Внимание! Никогда не указывайте полный номер карты, срок действия или код безопасности.</b></p>
                    </div>
                </div>
            </div>
        </div>

        <div class='card'>
            <a class='d-block w-100 faq_question_link collapsed' data-toggle='collapse' href='#answer3' aria-expanded='false'>
                <div class='card-header'>
                    <h4 class='card-title w-100 position-relative'>
                        <div class='faq_text faq_question_text'>Третий шаг</div>
                    </h4>
                </div>
            </a>
            <div id='answer3' class='collapse' data-parent='#faq'>
                <div class='card-body'>
                    <div class='faq_text'>
                        <p>4) После добавления карт перейдите в раздел <a href="/cashback">"Таблица кешбека"</a> и проставьте кешбек по вашим картам.</p>
                        <p>Для этого нажимайте на название колонки содержащий вашу карту (например <i>"*1234 (Сбербанк)"</i>).</p>
                        <p>В открывшемся окне проставьте размер кешбека напротив тех категорий которые у вас есть.</p>
                        <p>Так же, при желании вы можете проставить MCC коды. Далее вы можете использовать их номер для быстрого поиска категории кешбека.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class='card'>
            <a class='d-block w-100 faq_question_link collapsed' data-toggle='collapse' href='#answer4' aria-expanded='false'>
                <div class='card-header'>
                    <h4 class='card-title w-100 position-relative'>
                        <div class='faq_text faq_question_text'>Четвертый шаг</div>
                    </h4>
                </div>
            </a>
            <div id='answer4' class='collapse' data-parent='#faq'>
                <div class='card-body'>
                    <div class='faq_text'>
                        <p>5) Перейдите в <a href="/profile">"Ваш Профиль"</a> и сгенерируйте персональную ссылку нажав на "сгенерировать новую".</p>
                        <p>После этого нажмите на ссылку "перейти", над полем со сгенерированным кодом. У вас в новой вкладке откроется ваша персональная страница.</p>
                        <p>Можете добавить эту страницу в закладки вашего браузера или вынести на рабочий стол, чтобы иметь быстрый доступ к ней.</p>
                        <br>
                        <p>Так же вы можете поделиться адресом этой страницы с любым человеком кто пользуется с вами одной картой (супруг, ребенок и т.д.).</p>
                        <p>Используя данную ссылку невозможно попасть в ваш Личный Кабинет. Так что все ваши данные останутся в безопасности.</p>
                        <br>
                        <p><b>Обратите внимание, что если вы позже сгенерируете новую ссылку, то прежние перестанут действовать!</b></p>
                    </div>
                </div>
            </div>
        </div>

    </div>
@stop
