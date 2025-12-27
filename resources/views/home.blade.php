@extends('layouts.app')

@section('subtitle', 'Welcome')
@section('content_header_title', 'Welcome')

@section('content_body')

    {{-- ========== ПАНЕЛЬ ДЛЯ НАСТРОЕННЫХ ПОЛЬЗОВАТЕЛЕЙ ========== --}}
    @if($is_configured ?? false)
        <!-- Welcome Hero -->
        <div class="card border-0 shadow-lg mb-4 overflow-hidden">
            <div class="card-body p-0">
                <div class="row g-0">
                    <div class="col-12 p-5 text-center">
                        <h1 class="display-5 fw-bold mb-3">
                            <span class="text-success">Всё готово!</span> 👋
                        </h1>
                        <p class="lead text-muted mb-0">
                            Ваш кешбэк настроен. Используйте быстрые ссылки для работы.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        @if(($daysUntilMonthEnd ?? 99) <= 7)
        <!-- Month End Reminder -->
        <div class="alert alert-warning border-0 shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-triangle fa-2x me-3 text-warning"></i>
                <div>
                    <h5 class="alert-heading mb-1">Не забудьте обновить кешбэк на следующий месяц!</h5>
                    <p class="mb-0">
                        <a href="/cashback" class="alert-link">Перейти к таблице &rarr;</a>
                    </p>
                </div>
            </div>
        </div>
        @endif

        <!-- Quick Actions -->
        <div class="row mb-2">
            <div class="col-12">
                <h4 class="mt-3">
                    <i class="fas fa-bolt text-warning me-2"></i>
                    Быстрые действия
                </h4>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <!-- Personal Link -->
            <div class="col-lg-4 col-md-6">
                <a href="{{ route('search.index', ['token' => $search_token]) }}"
                   target="_blank" class="text-decoration-none">
                    <div class="card action-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center flex-grow-1 gap-3">
                                    <div class="action-icon bg-info bg-opacity-10 text-info me-5 flex-shrink-0">
                                        <i class="fas fa-link fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="card-title fw-bold mb-1 ml-3">Моя ссылка</h5>
                                        <p class="card-text text-muted small mb-0 ml-3">Для быстрого доступа</p>
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-info ms-3 flex-shrink-0"></i>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Cashback Table -->
            <div class="col-lg-4 col-md-6">
                <a href="/cashback" class="text-decoration-none">
                    <div class="card action-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center flex-grow-1 gap-3">
                                    <div class="action-icon bg-primary bg-opacity-10 text-primary me-5 flex-shrink-0">
                                        <i class="fas fa-table fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="card-title fw-bold mb-1 ml-3">Таблица кешбэка</h5>
                                        <p class="card-text text-muted small mb-0 ml-3">Просмотр и редактирование</p>
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-primary ms-3 flex-shrink-0"></i>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Manage Cards -->
            <div class="col-lg-4 col-md-6">
                <a href="/cards" class="text-decoration-none">
                    <div class="card action-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center flex-grow-1 gap-3">
                                    <div class="action-icon bg-success bg-opacity-10 text-success me-5 flex-shrink-0">
                                        <i class="fas fa-credit-card fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="card-title fw-bold mb-1 ml-3">Карты</h5>
                                        <p class="card-text text-muted small mb-0 ml-3">Управление картами</p>
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-success ms-3 flex-shrink-0"></i>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Manage Banks -->
            <div class="col-lg-4 col-md-6">
                <a href="/banks" class="text-decoration-none">
                    <div class="card action-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center flex-grow-1 gap-3">
                                    <div class="action-icon bg-warning bg-opacity-10 text-warning me-5 flex-shrink-0">
                                        <i class="fas fa-university fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="card-title fw-bold mb-1 ml-3">Банки</h5>
                                        <p class="card-text text-muted small mb-0 ml-3">Управление банками</p>
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-warning ms-3 flex-shrink-0"></i>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Categories -->
            <div class="col-lg-4 col-md-6">
                <a href="/categories" class="text-decoration-none">
                    <div class="card action-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center flex-grow-1 gap-3">
                                    <div class="action-icon bg-danger bg-opacity-10 text-danger me-5 flex-shrink-0">
                                        <i class="fas fa-tags fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="card-title fw-bold mb-1 ml-3">Категории</h5>
                                        <p class="card-text text-muted small mb-0 ml-3">Управление категориями</p>
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-danger ms-3 flex-shrink-0"></i>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <style>
            .bg-gradient-success {
                background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            }
            .bg-gradient-period {
                background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            }
            .bg-purple { background-color: #6f42c1 !important; }
            .text-purple { color: #6f42c1 !important; }
            .action-card {
                transition: all 0.2s ease;
            }
            .action-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,.15) !important;
            }
            .action-icon {
                width: 60px;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
            }
        </style>

    {{-- ========== ПАНЕЛЬ ДЛЯ НОВЫХ ПОЛЬЗОВАТЕЛЕЙ ========== --}}
    @else
    <!-- Hero Section -->
    <div class="card border-0 shadow-lg mb-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="row g-0">
                <div class="col-lg-8 p-5">
                    <h1 class="display-5 fw-bold mb-3">
                        <span class="text-primary">Управляйте кешбэком</span> удобно
                    </h1>
                    <p class="lead text-muted mb-4">
                        Храните информацию о кешбеке по всем картам в одном месте.
                        <br>Быстрая проверка категорий без входа в личный кабинет.
                    </p>
                    <a href="/banks" class="btn btn-primary btn-lg px-5 py-3 fw-semibold shadow hover-lift">
                        Начать настройку
                        <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
                <div class="col-lg-4 d-flex align-items-center justify-content-center bg-gradient-primary">
                    <div class="text-center p-4">
                        <i class="fas fa-credit-card fa-6x text-white mb-3 opacity-75"></i>
                        <div class="display-4 fw-bold text-white">+%</div>
                        <p class="text-white-50 small">Максимум выгоды</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .bg-gradient-primary {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
        }
        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.15) !important;
        }
    </style>

    <!-- Setup Steps -->
    <div class="row mb-2">
        <div class="col-12">
            <h3 class="text-center mb-4">
                Первичная настройка — 4 простых шага
            </h3>
        </div>
    </div>

    <!-- Step Cards -->
    <div class="row g-4" id="steps-accordion">
        <!-- Step 1: Banks -->
        <div class="col-lg-3 col-md-6">
            <div class="card step-card border-0 shadow-sm hover-lift">
                <div class="card-body text-center p-4">
                    <div class="step-number mb-3">1</div>
                    <div class="step-icon mb-3 text-primary">
                        <i class="fas fa-money-check-alt fa-3x"></i>
                    </div>
                    <h5 class="card-title fw-bold mb-3">Банки и категории</h5>
                    <p class="card-text text-muted small">Добавьте ваши банки и категории кешбека</p>
                    <a class="d-block w-100 faq_question_link collapsed" data-toggle="collapse" href="#answer1" aria-expanded="false">
                        <button class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-chevron-down me-1"></i> Подробнее
                        </button>
                    </a>
                    <div id="answer1" class="collapse mt-3" data-parent="#steps-accordion">
                        <div class="text-start small text-muted">
                            <p>Перейдите в разделы <a href="/banks">"Банки"</a> и <a href="/categories">"Категории"</a>, добавьте название ваших банков и нужные категории кешбека.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Cards -->
        <div class="col-lg-3 col-md-6">
            <div class="card step-card border-0 shadow-sm hover-lift">
                <div class="card-body text-center p-4">
                    <div class="step-number mb-3">2</div>
                    <div class="step-icon mb-3 text-success">
                        <i class="fas fa-credit-card fa-3x"></i>
                    </div>
                    <h5 class="card-title fw-bold mb-3">Ваши карты</h5>
                    <p class="card-text text-muted small">Добавьте карты с кешбеком</p>
                    <a class="d-block w-100 faq_question_link collapsed" data-toggle="collapse" href="#answer2" aria-expanded="false">
                        <button class="btn btn-outline-success btn-sm w-100">
                            <i class="fas fa-chevron-down me-1"></i> Подробнее
                        </button>
                    </a>
                    <div id="answer2" class="collapse mt-3" data-parent="#steps-accordion">
                        <div class="text-start small text-muted">
                            <p>Перейдите в раздел <a href="/cards">"Ваши карты"</a> и добавьте ваши карты. Указывайте название или последние 4 цифры.</p>
                            <p class="text-danger small"><b>Внимание! Никогда не указывайте полный номер карты!</b></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Cashback -->
        <div class="col-lg-3 col-md-6">
            <div class="card step-card border-0 shadow-sm hover-lift">
                <div class="card-body text-center p-4">
                    <div class="step-number mb-3">3</div>
                    <div class="step-icon mb-3 text-warning">
                        <i class="fas fa-percent fa-3x"></i>
                    </div>
                    <h5 class="card-title fw-bold mb-3">Таблица кешбека</h5>
                    <p class="card-text text-muted small">Укажите проценты по категориям</p>
                    <a class="d-block w-100 faq_question_link collapsed" data-toggle="collapse" href="#answer3" aria-expanded="false">
                        <button class="btn btn-outline-warning btn-sm w-100">
                            <i class="fas fa-chevron-down me-1"></i> Подробнее
                        </button>
                    </a>
                    <div id="answer3" class="collapse mt-3" data-parent="#steps-accordion">
                        <div class="text-start small text-muted">
                            <p>В разделе <a href="/cashback">"Таблица кешбека"</a> нажмите на название карты и проставьте проценты по категориям.</p>
                            <p>Можно указать MCC коды для быстрого поиска.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 4: Link -->
        <div class="col-lg-3 col-md-6">
            <div class="card step-card border-0 shadow-sm hover-lift">
                <div class="card-body text-center p-4">
                    <div class="step-number mb-3">4</div>
                    <div class="step-icon mb-3 text-info">
                        <i class="fas fa-link fa-3x"></i>
                    </div>
                    <h5 class="card-title fw-bold mb-3">Персональная ссылка</h5>
                    <p class="card-text text-muted small">Создайте ссылку для быстрого доступа</p>
                    <a class="d-block w-100 faq_question_link collapsed" data-toggle="collapse" href="#answer4" aria-expanded="false">
                        <button class="btn btn-outline-info btn-sm w-100">
                            <i class="fas fa-chevron-down me-1"></i> Подробнее
                        </button>
                    </a>
                    <div id="answer4" class="collapse mt-3" data-parent="#steps-accordion">
                        <div class="text-start small text-muted">
                            <p>В <a href="/profile">"Вашем Профиле"</a> сгенерируйте персональную ссылку. Добавьте её в закладки для быстрого доступа.</p>
                            <p>Ссылкой можно делиться с семьёй — доступ к личному кабинету закрыт.</p>
                            <p class="text-warning small"><b>При генерации новой ссылки старая перестаёт действовать!</b></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .step-number {
            width: 50px;
            height: 50px;
            line-height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0 auto;
        }
        .step-card {
            transition: all 0.3s ease;
        }
        .step-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 1rem 2rem rgba(0,0,0,.15) !important;
        }
        .step-arrow {
            font-weight: bold;
            font-size: 1.2rem;
            color: #0d6efd;
        }
    </style>
    @endif
@stop
