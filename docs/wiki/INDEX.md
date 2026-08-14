# Project Wiki Index
> Generated: 2026-08-14 13:22 | Models: 6 | Services: 5 | Controllers: 23 | Livewire: 2

## Quick Orientation
The skeleton application for the Laravel framework..
PHP ^8.2, Laravel 11, Livewire.

## Models (6)
| Model | Table | Key Relations | Traits |
|-------|-------|---------------|--------|
| AvailableAllCashback | `card_category_all_available_cashback` | category, card |  |
| Bank | `banks` |  |  |
| Cashback | `card_category_cashback` | category, card |  |
| Category | `categories` | cards, cashbacks |  |
| Role | `roles` |  |  |
| User | `users` | role | HasFactory, Notifiable |

## Services (5)
| Service | Methods | Dependencies |
|---------|---------|-------------|
| AiService | 2 | — |
| CashbackService | 4 | — |
| CategoryMatcher | 6 | — |
| CategoryService | 1 | — |
| FileStorageService | 1 | — |

## Controllers (23)
| Controller | Key Methods |
|------------|------------|
| ConfirmPasswordController | showConfirmForm, confirm, redirectPath |
| ForgotPasswordController | showLinkRequestForm, sendResetLinkEmail, broker |
| LoginController | showLoginForm, login, username, logout, redirectPath +2 |
| RegisterController | showRegistrationForm, register, redirectPath |
| ResetPasswordController | showResetForm, reset, broker, redirectPath |
| VerificationController | show, verify, resend, redirectPath |
| BankController | index, create, store, edit, update +1 |
| BotLinkController | show, store |
| CardController | index, create, store, edit, update +2 |
| CashbackController | index, allAvailableCashback, categoryShow, cardEdit, cardUpdate +5 |
| CategoryController | index, create, store, edit, update +3 |
| Controller | authorize, authorizeForUser, authorizeResource, validateWith, validate +1 |
| FileUploadController | store |
| HomeController | index |
| LandingController | index |
| MaxLinkController | show, store |
| MaxWebhookController |  |
| MiniAppController | handle |
| PageController | index |
| SearchController | index, manifest |
| SearchDataController | getFreshData |
| TelegramWebhookController |  |
| UsersController | index, create, store, edit, update +4 |

## Livewire (2)
| Component | View | Properties |
|-----------|------|------------|
| CategorySearchComponent | `livewire.category-search-component` | $search, $products, $categories, $filteredProducts |
| SearchComponent | `livewire.search-component` | $user, $search, $filteredCategoriesCashback, $isLoading |

## Topic Guides (business logic)
- [Example Topic — Краткое описание](topics/_example-topic.md)

## Top 5 God Nodes (High Dependency)
> High indegree = many classes depend on this | candidates for refactoring

| Class | Type | Indegree | Outdegree | Score |
|-------|------|----------|-----------|-------|
| **CashbackService** | service | 5 | 0 | 10 |
| **CashbackController** | controller | 0 | 4 | 4 |
| **FileStorageService** | service | 1 | 0 | 2 |
| **FileUploadController** | controller | 0 | 1 | 1 |
| **SearchDataController** | controller | 0 | 1 | 1 |

## Detailed Maps
- [Models](maps/models.md) — полные fillable, casts, relationships
- [Services](maps/services.md) — все методы с сигнатурами
- [Controllers](maps/controllers.md) — все методы контроллеров
- [Dependencies](maps/dependencies.md) — граф зависимостей и god-nodes
- [Routes](maps/routes.md) — все роут-файлы
- [Livewire](maps/livewire.md) — компоненты и их views
- [Schedule](maps/schedule.md) — cron-задачи
