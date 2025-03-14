<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

## Установка

Для установки необходимо:
1. Склонировать репозиторий
2. Установить зависимости
3. Первоначальная настройка
4. Настроить базу данных
5. Запустить сервер 
6. Открыть в браузере http://localhost:8000

## Установка зависимостей

```bash
  composer install
```

## Первоначальная настройка

```bash
    cp .env.example .env
    php artisan key:generate
```

## Настройка базы данных

В файле .env укажите настройки для подключения к вашей базе данных.
После этого сделайте миграции и заполнение базы данных тестовыми данными.

```bash
    php artisan migrate --seed
```

## Запуск сервера

```bash
    php artisan serve
```

## Открытие в браузере

http://localhost:8000
