# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

LaraCash - это Laravel-приложение для управления кешбэк-предложениями по банковским картам. Приложение позволяет пользователям отслеживать категориальные кешбэки, управлять картами и находить оптимальные предложения.

## Key Technology Stack

- **Backend**: Laravel 11.x with PHP 8.2+
- **Frontend**: Livewire 3.6, Bootstrap 5.2, Vite 6.0
- **Database**: MySQL (с возможностью SQLite)
- **Admin Panel**: Laravel AdminLTE 3.14
- **Testing**: PestPHP 3.7

## Development Commands

### Установка и первоначальная настройка
```bash
# Установка зависимостей
composer install
npm install

# Копирование env и генерация ключа
cp .env.example .env
php artisan key:generate

# Миграции и заполнение БД
php artisan migrate --seed
```

### Разработка
```bash
# Запуск сервера разработки
php artisan serve

# Запуск с очередями и сборкой фронтенда (в одной команде)
composer run dev

# Сборка фронтенда
npm run dev
npm run build

# Запуск тестов
./vendor/bin/pest
./vendor/bin/pest --filter TestName

# Форматирование кода
./vendor/bin/pint

# Очереди
php artisan queue:work
php artisan queue:listen --tries=1
```

### База данных
```bash
# Создание миграции
php artisan make:migration create_table_name

# Откат последней миграции
php artisan migrate:rollback

# Обновление БД
php artisan migrate:fresh --seed
```

## Application Architecture

### Core Models

- **User**: Стандартная модель Laravel с ролями и токеном поиска
- **Bank**: Банки пользователя (title, user_id)
- **Card**: Карты пользователя (number, bank_id, color, cashback_json)
- **Category**: Категории расходов (title, keywords, user_id)
- **Cashback**: Кешбэк предложения (связь карт и категорий)
- **Role**: Роли пользователей (admin, user)

### Key Relationships

```php
// User
public function role(): BelongsTo

// Card
public function bank(): BelongsTo
public function categories(): BelongsToMany // with pivot table
public function cashbacks(): HasMany

// Category
public function cards(): BelongsToMany // with pivot table
public function cashbacks(): BelongsToMany

// Bank
public function cards(): HasMany
```

### Controllers Structure

- **CardController**: CRUD операций с картами + AJAX-обновления
- **BankController**: Управление банками
- **CategoryController**: Управление категориями
- **SearchController**: Поиск по токену + manifest генерация
- **MiniAppController**: Telegram Mini App интеграция с валидацией
- **CashbackController**: Управление кешбэк предложениями

### Livewire Components

- **SearchComponent**: Реальный поиск категорий с задержкой
- **CategorySearchComponent**: Поиск и фильтрация категорий

### Special Features

1. **Telegram Mini App Integration**:
   - Валидация Telegram WebApp init data по официальной спецификации
   - Генерация динамических manifest файлов
   - Токен доступа для поиска

2. **Search System**:
   - Поиск по ключевым словам в категориях
   - Задержка при поиске для уменьшения нагрузки на БД
   - Мгновенные результаты через Livewire

3. **PWA Support**:
   - Динамическая генерация manifest с токеном пользователя
   - Иконки различных размеров

## Database Schema

Key tables:
- `users` + `roles`: Пользователи и роли
- `banks`: Банки (user scoped)
- `cards`: Карты (user scoped)
- `categories`: Категории (user scoped с keywords)
- `card_category_cashback`: Pivot таблица с кешбэком (cashback_percentage, mcc, updated_at)
- `cashbacks`: Кешбэк предложения
- `card_category_all_available_cashback`: Все доступные кешбэки

## Important Implementation Details

### User Scoping
Большинство моделей привязаны к пользователям через `user_id`. Всегда используйте фильтрацию:
```php
Card::query()->where('user_id', auth()->user()->id)
```

### Telegram WebApp Validation
Валидация initData строго по официальной спецификации Telegram:
- Использование `rawurldecode()` вместо `parse_str()`
- Проверка HMAC-SHA256 подписи
- Сортировка параметров по ключам в ASCII порядке

### Search Implementation
- Поиск реализован через LIKE по полю `keywords` в таблице `categories`
- Используется задержка (debounce) для уменьшения количества запросов
- Результаты обновляются в реальном времени через Livewire

### Cashback Logic
- Кешбэк хранится в pivot таблице `card_category_cashback`
- Поддерживаются проценты и MCC коды
- Система приоритетов для выбора оптимального кешбэка

## File Structure Notes

- Все временные файлы имеют суффикс `~` и должны игнорироваться
- Основные blade шаблоны в `resources/views/`
- Livewire компоненты в `app/Livewire/`
- Стили в `resources/sass/` с использованием Bootstrap 5
- PWA иконки в `public/icons/`

## Testing Strategy

Используется PestPHP для тестирования. Запуск:
```bash
./vendor/bin/pest
./vendor/bin/pest --group FeatureName
```

## Security Notes

- Валидация Telegram WebApp данных обязательна
- Все запросы к user-scoped данным должны проверять авторизацию
- Используется стандартная Laravel авторизация и политики