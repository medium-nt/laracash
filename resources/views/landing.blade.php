<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="Максимальный кешбэк по вашим банковским картам. Учитывайте и выбирайте лучшие предложения для каждой покупки.">
    <title>Кешбэк по картам</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
          rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        /* Глобальные стили для фона */
        body {
            background-color: #f8f9fa;
            background-image:
                radial-gradient(circle at 20% 50%, rgba(13, 110, 253, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(25, 135, 84, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(255, 193, 7, 0.03) 0%, transparent 50%);
            background-attachment: fixed;
            position: relative;
            overflow-x: hidden; /* Предотвращаем горизонтальный скролл на всех устройствах */
            width: 100%;
        }

        /* Декоративные паттерны */
        .pattern-dots {
            background-image: radial-gradient(circle, #d1d5db 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.3;
        }

        /* Декоративные элементы */
        .decorative-shape {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(45deg, rgba(13, 110, 253, 0.1), rgba(25, 135, 84, 0.05));
            filter: blur(40px);
            z-index: -1;
            pointer-events: none; /* Предотвращаем взаимодействие */
        }

        .shape-1 {
            width: 300px;
            height: 300px;
            top: 10%;
            right: -100px; /* Уменьшаем отрицательное значение */
        }

        .shape-2 {
            width: 200px;
            height: 200px;
            bottom: 20%;
            left: -80px; /* Уменьшаем отрицательное значение */
            background: linear-gradient(45deg, rgba(25, 135, 84, 0.1), rgba(13, 110, 253, 0.05));
        }

        /* Стили для калькулятора */
        .calculator-card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
            position: relative;
            contain: layout style paint; /* Оптимизация производительности и предотвращение переполнения */
        }

        .calculator-card::before {
            content: '';
            position: absolute;
            top: -10%;
            left: -10%;
            width: 120%;
            height: 120%;
            background: radial-gradient(circle, rgba(13, 110, 253, 0.02) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
            pointer-events: none; /* Предотвращаем блокировку кликов */
            z-index: 0; /* Фон ниже ползунка */
        }

        /* Обеспечиваем, что ползунок выше фона */
        .calculator-card .form-range {
            position: relative;
            z-index: 10;
        }

        /* Предотвращаем горизонтальный скролл для всех секций */
        .container-fluid {
            overflow-x: hidden;
        }

        .hero-header {
            overflow-x: hidden;
        }

        .description-section {
            overflow-x: hidden;
        }

        .calculator-section {
            overflow-x: hidden;
            overflow-y: visible; /* Явно указываем, что вертикальный скролл должен быть видим */
        }

        .stats-section {
            overflow-x: hidden;
        }

        /* Общие контейнеры */
        .container {
            overflow-x: hidden;
        }

        section {
            overflow-x: hidden;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(10px, -10px) rotate(120deg); }
            66% { transform: translate(-10px, 10px) rotate(240deg); }
        }

        #spending-slider {
            height: 8px;
            background: #e9ecef;
            outline: none;
            -webkit-appearance: none;
            width: 100%;
            cursor: pointer;
            z-index: 10;
            position: relative;
            pointer-events: auto;
        }

        #spending-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            background: #0d6efd;
            cursor: pointer;
            border-radius: 50%;
            margin-top: -6px; /* Центрирование ползунка по высоте */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            z-index: 10;
            position: relative;
        }

        #spending-slider::-moz-range-thumb {
            width: 20px;
            height: 20px;
            background: #0d6efd;
            cursor: pointer;
            border-radius: 50%;
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            z-index: 10;
            position: relative;
        }


        #savings-amount {
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .result-box {
            background-color: #f8f9fa;
            border-radius: 8px;
        }

        .pulse {
            animation: pulse 0.3s ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Мобильная адаптация */
        @media (max-width: 576px) {
            /* Общие стили для заголовков */
            h2 {
                font-size: 1.5rem;
                margin-bottom: 30px;
            }

            /* Заголовок */
            .hero-title {
                font-size: 2.5rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }

            /* Описание */
            .description-section {
                padding: 40px 0;
            }

            .feature-item {
                padding: 12px 0;
            }

            .feature-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
                margin-right: 15px;
            }

            .feature-text h4 {
                font-size: 1.1rem;
            }

            .feature-text p {
                font-size: 0.9rem;
            }

            .cta-column {
                padding: 30px 20px;
                margin-top: 30px;
            }

            .cta-title {
                font-size: 1.4rem;
                margin-bottom: 15px;
            }

            .btn-cta-hero {
                padding: 12px 30px;
                font-size: 1rem;
            }

            .feature-image {
                height: 200px;
            }

            .feature-image i {
                font-size: 3rem;
            }

            /* Калькулятор */
            .calculator-card {
                overflow: hidden; /* Возвращаем overflow только для мобильных */
            }

            #spending-slider {
                height: 12px;
            }

            #spending-slider::-webkit-slider-thumb {
                width: 28px;
                height: 28px;
                margin-top: -8px;
            }

            #spending-slider::-moz-range-thumb {
                width: 28px;
                height: 28px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            #savings-amount {
                font-size: 1.5rem;
            }

            .calculator-section h2 {
                font-size: 1.5rem;
            }

            .calculator-card {
                margin: 0 10px;
            }

            .calculator-section .form-label {
                font-size: 0.9rem;
            }

            /* Статистика */
            .stats-section .container {
                padding-left: 20px;
                padding-right: 20px;
            }

            .stats-section .col-12 {
                margin-bottom: 20px;
            }

            .stat-card {
                padding: 25px 20px;
                height: 100%;
                margin-bottom: 0;
                max-width: 400px;
                margin-left: auto;
                margin-right: auto;
            }

            .stat-icon {
                width: 70px;
                height: 70px;
                font-size: 28px;
                margin-bottom: 20px;
            }

            .stat-number {
                font-size: 2.2rem;
                line-height: 1.2;
            }

            .stat-label {
                font-size: 0.95rem;
                line-height: 1.4;
                margin-top: 5px;
            }

            /* Преимущества */
            .advantage-card {
                margin-bottom: 20px;
            }

            .advantage-card .card-body {
                padding: 25px 20px;
            }

            .advantage-card .icon-wrapper i {
                font-size: 2.5rem;
            }

            .advantage-card .card-title {
                font-size: 1.1rem;
            }

            .advantage-card .card-text {
                font-size: 0.9rem;
            }

            /* Отзывы */
            .review-card {
                margin-bottom: 20px;
            }

            .review-card .card-body {
                padding: 20px;
            }

            .avatar-circle {
                width: 40px !important;
                height: 40px !important;
            }

            .avatar-circle i {
                font-size: 18px !important;
            }

            .review-card h6 {
                font-size: 0.95rem;
            }

            .rating {
                font-size: 0.8rem;
            }

            /* Декоративные элементы */
            .decorative-shape {
                display: none;
            }

            .shape-1, .shape-2 {
                display: none;
            }

            /* Секции */
            .container {
                padding-left: 15px;
                padding-right: 15px;
            }

            .stats-section {
                padding: 40px 0;
            }

            .calculator-section {
                padding: 40px 0;
            }
        }

        /* Планшеты */
        @media (min-width: 577px) and (max-width: 768px) {
            .hero-title {
                font-size: 3rem;
            }

            .feature-icon {
                width: 45px;
                height: 45px;
                font-size: 19px;
            }

            .stat-card {
                padding: 25px 20px;
                max-width: none;
                margin-left: 0;
                margin-right: 0;
            }

            .stat-icon {
                width: 65px;
                height: 65px;
                font-size: 26px;
                margin-bottom: 15px;
            }

            .stat-number {
                font-size: 2.2rem;
            }

            .stat-label {
                font-size: 0.9rem;
            }
        }

        /* Маленькие десктопы */
        @media (min-width: 769px) and (max-width: 992px) {
            .feature-item:hover {
                padding-left: 10px;
                margin-left: -10px;
                padding-right: 10px;
                margin-right: -10px;
            }

            .cta-column {
                padding: 35px;
            }
        }

        /* Оптимизация для тач-устройств */
        @media (hover: none) and (pointer: coarse) {
            .feature-item:hover {
                transform: none;
                background: transparent;
                padding-left: 15px;
                margin-left: 0;
                padding-right: 15px;
                margin-right: 0;
            }

            .advantage-card:hover {
                transform: none;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            }

            .review-card:hover {
                transform: none;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            }

            .stat-card:hover {
                transform: none;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            }

            .cta-button:hover {
                transform: none;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            }

            .btn-cta-hero:hover {
                transform: none;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            }
        }

        /* Улучшенная читаемость на мобильных */
        @media (max-width: 576px) {
            body {
                font-size: 16px;
                line-height: 1.6;
                overflow-x: hidden; /* Предотвращаем горизонтальный скролл */
                width: 100%;
            }

            .card-body {
                padding: 20px !important;
            }

            .container {
                padding-left: 15px;
                padding-right: 15px;
                max-width: 100%;
                overflow-x: hidden;
            }

            /* Исправление для всех секций */
            section {
                margin-left: 0;
                margin-right: 0;
                padding-left: 15px;
                padding-right: 15px;
                overflow-x: hidden;
            }

            /* Специально для секции описания */
            .description-section {
                padding-left: 15px;
                padding-right: 15px;
                margin-left: 0;
                margin-right: 0;
            }

            /* Улучшение контрастности */
            .text-muted {
                color: #495057 !important;
            }

            /* Оптимизация отступов */
            section {
                margin-bottom: 40px;
            }

            /* Улучшение для кнопок */
            .btn {
                min-height: 44px; /* Рекомендуемый минимальный размер для тач-устройств */
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            /* Восстанавливаем стили для ползунка */
            .form-range {
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            #spending-slider {
                pointer-events: auto !important;
                z-index: 10 !important; /* Высокий z-index для мобильных */
                position: relative !important;
            }

            /* Предотвращаем скролл в калькуляторе */
            .calculator-section {
                overflow-x: hidden;
            }

            .calculator-card .card-body {
                padding: 20px 15px !important; /* Уменьшаем отступы */
            }

            .result-box {
                padding: 20px 15px !important; /* Уменьшаем отступы */
            }
        }

        /* Стили для карточек преимуществ */
        .advantage-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .advantage-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .icon-wrapper i {
            transition: all 0.3s ease;
        }

        .advantage-card:hover .icon-wrapper i {
            transform: scale(1.1);
        }

        .advantage-card .card-title {
            transition: all 0.3s ease;
        }

        .advantage-card:hover .card-title {
            color: #0d6efd;
        }

        /* Стили для карточек отзывов */
        .review-card {
            transition: all 0.3s ease;
        }

        .review-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        }

        .rating {
            font-size: 0.9rem;
        }

        .avatar-circle {
            transition: all 0.3s ease;
        }

        .review-card:hover .avatar-circle {
            transform: scale(1.05);
        }

        /* Стили для заголовка */
        .hero-title {
            background: linear-gradient(45deg, #ffffff, #f8f9fa, #ffffff);
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradientShift 3s ease infinite, fadeInDown 1s ease;
            line-height: 1.1;
            word-wrap: break-word;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-subtitle {
            animation: fadeInUp 1s ease 0.3s both;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-header {
            background: linear-gradient(135deg, #0d6efd 0%, #004085 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 3s infinite;
            pointer-events: none;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        /* Стили для кнопок CTA */
        .cta-button {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            z-index: 1;
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cta-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
            z-index: -1;
        }

        .cta-button:hover::before {
            left: 100%;
        }

        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.3);
        }

        .cta-pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7);
            }
            70% {
                box-shadow: 0 0 0 20px rgba(13, 110, 253, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
            }
        }

        .cta-button i {
            transition: transform 0.3s ease;
        }

        .cta-button:hover i {
            transform: translateX(5px);
        }

        /* Стили для кнопки входа */
        .btn-login {
            background: linear-gradient(135deg, #198754, #157347);
            border: none;
            padding: 10px 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #157347, #125636);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(25, 135, 84, 0.3);
        }

        .btn-login i {
            margin-left: 8px;
        }

        /* Стили для секции статистики */
        .stats-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 60px 0;
        }

        .stats-section h2 {
            margin-bottom: 40px;
        }

        .stats-section .row {
            align-items: stretch;
        }

        @media (max-width: 576px) {
            .stats-section .row.g-4 {
                gap: 1rem !important;
            }
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 30px 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: none;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0d6efd, #004085);
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #0d6efd, #004085);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 10px;
            font-family: 'Arial', sans-serif;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.95rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Анимация счетчика */
        .counter {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.6s ease;
        }

        .counter.animate {
            opacity: 1;
            transform: translateY(0);
        }

        /* Улучшение блока описания */
        .description-section {
            padding: 60px 0;
        }

        .feature-list {
            list-style: none;
            padding: 0;
        }

        .feature-item {
            padding: 15px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .feature-item:last-child {
            border-bottom: none;
        }

        .feature-item:hover {
            transform: translateX(5px);
            background: linear-gradient(90deg, rgba(13, 110, 253, 0.05), transparent);
            padding-left: 10px;
            margin-left: -10px;
            padding-right: 10px;
            margin-right: -10px;
            border-radius: 8px;
        }

        .feature-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #0d6efd, #004085);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-right: 20px;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .feature-item:hover .feature-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 25px rgba(13, 110, 253, 0.3);
        }

        .feature-text h4 {
            margin-bottom: 5px;
            color: #0d6efd;
            font-weight: 600;
        }

        .feature-text p {
            margin-bottom: 0;
            color: #6c757d;
        }

        .cta-column {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 40px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 20px;
            position: relative;
            overflow: hidden;
        }

        .cta-column::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(13, 110, 253, 0.05) 0%, transparent 70%);
            animation: float 8s ease-in-out infinite;
        }

        .cta-content {
            position: relative;
            z-index: 1;
        }

        .cta-title {
            font-size: 1.8rem;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 20px;
        }

        .btn-cta-hero {
            background: linear-gradient(135deg, #0d6efd, #004085);
            border: none;
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(13, 110, 253, 0.3);
        }

        .btn-cta-hero:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(13, 110, 253, 0.4);
        }

        .btn-cta-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-cta-hero:hover::before {
            left: 100%;
        }

        .btn-cta-hero i {
            margin-left: 10px;
            transition: transform 0.3s ease;
        }

        .btn-cta-hero:hover i {
            transform: translateX(5px);
        }

        .feature-image {
            width: 100%;
            height: 300px;
            background: linear-gradient(135deg, #0d6efd, #004085);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            position: relative;
            overflow: hidden;
        }

        .feature-image::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite reverse;
        }

        .feature-image i {
            font-size: 4rem;
            z-index: 1;
        }
    </style>
</head>
<body>

<!-- Декоративные элементы фона -->
<div class="decorative-shape shape-1"></div>
<div class="decorative-shape shape-2"></div>

<!-- Заголовок -->
<header class="container-fluid p-5 hero-header text-light text-center">
    <div class="hero-content">
        <h1 class="display-1 hero-title">Кешбэк на максимум!</h1>
        <p class="lead hero-subtitle">Получайте максимальный кэшбэк с каждой покупки!</p>
    </div>
</header>

<!-- Описание услуги -->
<section class="description-section">
    <div class="container">
        <div class="row align-items-center">
        <div class="col-lg-7">
            <h2 class="mb-4">Что такое кешбэк?</h2>
            <ul class="feature-list">
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-credit-card-2-front"></i>
                    </div>
                    <div class="feature-text">
                        <h4>Много карт = много условий</h4>
                        <p>У вас несколько кредитных карт, у каждой свои условия кешбэка. Держать всю эту информацию в голове сложно.</p>
                    </div>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="feature-text">
                        <h4>Теряете время и деньги</h4>
                        <p>На кассе пытаетесь вспомнить какая карта выгоднее, а в итоге используете не самую лучшую.</p>
                    </div>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-clipboard-data"></i>
                    </div>
                    <div class="feature-text">
                        <h4>Удобная таблица учета</h4>
                        <p>Мы предлагаем простой инструмент для ведения таблицы кешбэка по всем вашим картам в одном месте.</p>
                    </div>
                </li>
            </ul>
        </div>
        <div class="col-lg-5">
            <div class="cta-column">
                <div class="cta-content">
                    <div class="feature-image mb-4">
                        <i class="bi bi-credit-card-2-back"></i>
                    </div>
                    @if (Route::has('login'))
                        <div class="cta-title">Начните экономить уже сегодня!</div>
                        @auth
                            <a href="{{ url('/home') }}" class="btn btn-cta-hero btn-lg">
                                Войти в кабинет <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-cta-hero btn-lg">
                                Войти в кабинет <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        @endauth
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Калькулятор экономии -->
<section class="calculator-section bg-light py-5">
    <div class="container">
        <h2 class="text-center mb-5">Узнайте, сколько вы сэкономите!</h2>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card calculator-card">
                    <div class="card-body p-4">
                        <!-- Ползунок -->
                        <div class="mb-4">
                            <label class="form-label">Ваши ежемесячные траты по картам:</label>
                            <input type="range" class="form-range" id="spending-slider"
                                   min="0" max="200000" step="1000" value="50000">
                            <div class="d-flex justify-content-between text-muted small">
                                <span>0 ₽</span>
                                <span id="current-spending" style=" color:#007bff; font-weight: bold;">50 000 ₽</span>
                                <span>200 000 ₽</span>
                            </div>
                        </div>

                        <!-- Результат -->
                        <div class="result-box text-center py-3">
                            <p class="mb-2">Ваша дополнительня экономия в месяц:</p>
                            <h2 class="text-success" id="savings-amount">+ 1 000 ₽</h2>
                            <p class="text-muted mb-0">Ваша экономия в год: <span id="yearly-savings">+ 12 000 ₽</span></p>
                        </div>

                        <!-- Призыв к действию -->
                        <div class="text-center mt-4">
                            <a href="{{ route('login') }}" class="btn btn-primary btn-lg cta-button cta-pulse">
                                Начать экономить больше! <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Статистика -->
<section class="stats-section">
    <div class="container">
        <h2 class="text-center mb-5">Наши достижения в цифрах</h2>
        <div class="row g-4 justify-content-center">
            <div class="col-12 col-md-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="counter">
                        <div class="stat-number" data-target="117">0</div>
                        <div class="stat-label">Довольных пользователей</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    <div class="counter">
                        <div class="stat-number" data-target="1755000">0</div>
                        <div class="stat-label">Рублей сэкономлено</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-percent"></i>
                    </div>
                    <div class="counter">
                        <div class="stat-number" data-target="8">0</div>
                        <div class="stat-label">Средний % кешбэка</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Преимущества -->
<section class="container my-5 position-relative">
    <div class="decorative-shape" style="width: 150px; height: 150px; top: -50px; right: 20%; background: linear-gradient(45deg, rgba(255, 193, 7, 0.08), rgba(25, 135, 84, 0.04));"></div>
    <h2 class="text-center mb-5 position-relative">Преимущества нашего сервиса</h2>
    <div class="row gx-4 gy-4">
        <div class="col-md-4">
            <div class="card advantage-card h-100 border-0 shadow-sm">
                <div class="card-body text-center p-4">
                    <div class="icon-wrapper mb-3">
                        <i class="bi bi-lightning-charge fs-1 text-primary"></i>
                    </div>
                    <h3 class="card-title h4">Мгновенный поиск</h3>
                    <p class="card-text text-muted">Найдите карту с максимальным кешбэком всего за 2 секунды</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card advantage-card h-100 border-0 shadow-sm">
                <div class="card-body text-center p-4">
                    <div class="icon-wrapper mb-3">
                        <i class="bi bi-table fs-1 text-success"></i>
                    </div>
                    <h3 class="card-title h4">Все в одном месте</h3>
                    <p class="card-text text-muted">Вся информация о ваших картах и кешбэке хранится в единой таблице</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card advantage-card h-100 border-0 shadow-sm">
                <div class="card-body text-center p-4">
                    <div class="icon-wrapper mb-3">
                        <i class="bi bi-robot fs-1 text-warning"></i>
                    </div>
                    <h3 class="card-title h4">Быстрое заполнение</h3>
                    <p class="card-text text-muted">ИИ помогает быстро заполнить таблицу кешбэка по скриншоту из банковского приложения</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Отзывы клиентов -->
<section class="container my-5 position-relative">
    <div class="decorative-shape" style="width: 120px; height: 120px; bottom: 20px; left: 10%; background: linear-gradient(45deg, rgba(13, 110, 253, 0.06), rgba(25, 135, 84, 0.03));"></div>
    <h2 class="text-center mb-5 position-relative">Отзывы наших клиентов</h2>
    <div class="row gx-4 gy-4">
        <div class="col-md-4">
            <div class="card review-card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Иван Иванов</h6>
                            <div class="rating text-warning">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                            </div>
                        </div>
                    </div>
                    <p class="card-text">"Раньше тратил 5 минут чтобы вспомнить какая карта лучше, теперь мгновенно вижу нужную!"</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card review-card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Марина Петрова</h6>
                            <div class="rating text-warning">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-half"></i>
                            </div>
                        </div>
                    </div>
                    <p class="card-text">"Наконец-то все мои карты и их кешбэк в одном месте! Муж спрашивает — показываю сразу."</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card review-card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-info text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Алексей Смирнов</h6>
                            <div class="rating text-warning">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                            </div>
                        </div>
                    </div>
                    <p class="card-text">"Больше не держу в голове 10+ карт и их условий. Всегда знаю где максимальный кешбэк!"</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card review-card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-warning text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Елена Козлова</h6>
                            <div class="rating text-warning">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                            </div>
                        </div>
                    </div>
                    <p class="card-text">"Удобно что добавили загрузку скриншота странцы кешбэка для каждой карты. Всегда можно взглянуть глазами не заходя каждый раз в приложение банка."</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card review-card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-danger text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Дмитрий Волков</h6>
                            <div class="rating text-warning">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-half"></i>
                            </div>
                        </div>
                    </div>
                    <p class="card-text">"В магазине в спешке не успевал вспомнить карты. Теперь просто открыл приложение и выбрал нужную!"</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card review-card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Ольга Новикова</h6>
                            <div class="rating text-warning">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                            </div>
                        </div>
                    </div>
                    <p class="card-text">"Очень удобно делиться таблицей с семьей. Все знают по какой карте что лучше платить."</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Контакты -->
<section class="container my-5">
    <h2 class="text-center mb-4">Контактная информация</h2>
    <div class="row gx-5 gy-4">
        <div class="col-md-4">
            <h3>Адрес</h3>
            <address>
                г. Москва, ул. Тверская, д. 12<br>
                Бизнес-центр "Тверской"
            </address>
        </div>
        <div class="col-md-4">
            <h3>Телефон</h3>
            <p><a href="tel:+74951234567">+7 (495) 123-45-67</a></p>
        </div>
        <div class="col-md-4">
            <h3>Электронная почта</h3>
            <p><a href="mailto:info@example.com">info@example.com</a></p>
        </div>
    </div>
</section>

<!-- Подвал -->
<footer class="container-fluid p-5 bg-dark text-light text-center position-relative">
    <div class="decorative-shape" style="width: 200px; height: 200px; top: -100px; right: 10%; background: linear-gradient(45deg, rgba(13, 110, 253, 0.1), rgba(255, 255, 255, 0.02));"></div>
    <p class="position-relative">© 2023 Кешбэк по картам. Все права защищены.</p>
</footer>

<script>
    // JavaScript для калькулятора экономии
    document.addEventListener('DOMContentLoaded', function() {
        const slider = document.getElementById('spending-slider');
        const currentSpendingEl = document.getElementById('current-spending');
        const savingsAmountEl = document.getElementById('savings-amount');
        const yearlySavingsEl = document.getElementById('yearly-savings');

        // Проверка наличия элементов
        if (!slider || !currentSpendingEl || !savingsAmountEl || !yearlySavingsEl) {
            return;
        }

        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        }

        function updateCalculator() {
            const spending = parseInt(slider.value);
            const monthlySavings = Math.round(spending * 0.05);
            const yearlySavings = monthlySavings * 12;

            currentSpendingEl.textContent = formatNumber(spending) + ' ₽';
            savingsAmountEl.textContent = '+' + formatNumber(monthlySavings) + ' ₽';
            yearlySavingsEl.textContent = '+' + formatNumber(yearlySavings) + ' ₽';

            // Добавляем анимацию к результату
            savingsAmountEl.classList.add('pulse');
            setTimeout(() => {
                savingsAmountEl.classList.remove('pulse');
            }, 300);
        }

        // Первоначальное обновление
        updateCalculator();

        // Обновление при изменении ползунка
        slider.addEventListener('input', updateCalculator);
    });

    // Анимация счетчиков статистики
    const counters = document.querySelectorAll('.stat-number');
    const speed = 200; // скорость анимации

    const countUp = (counter) => {
        const target = +counter.getAttribute('data-target');
        const count = +counter.innerText;
        const increment = target / speed;

        if (count < target) {
            counter.innerText = Math.ceil(count + increment);
            setTimeout(() => countUp(counter), 10);
        } else {
            counter.innerText = target.toLocaleString('ru-RU');
        }
    };

    // Intersection Observer для запуска анимации при прокрутке
    const observerOptions = {
        threshold: 0.5,
        rootMargin: '0px'
    };

    const observerCallback = (entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counter = entry.target;
                const counterContainer = counter.closest('.counter');

                // Добавляем анимацию появления
                counterContainer.classList.add('animate');

                // Запускаем счетчик
                setTimeout(() => {
                    countUp(counter);
                }, 300);

                // Отключаем наблюдение после запуска
                observer.unobserve(entry.target);
            }
        });
    };

    const observer = new IntersectionObserver(observerCallback, observerOptions);

    // Начинаем наблюдение за счетчиками
    counters.forEach(counter => {
        observer.observe(counter);
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous">
</script>
</body>
</html>
