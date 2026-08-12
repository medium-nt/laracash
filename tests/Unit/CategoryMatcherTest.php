<?php

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Services\CategoryMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__FILE__);

beforeEach(function () {
    // Роли для UserFactory (factory использует role_id => rand(1, 2))
    Role::create(['name' => 'user']);
    Role::create(['name' => 'admin']);

    $this->user = User::factory()->create();
    $this->matcher = new CategoryMatcher;
});

test('normalize: нижний регистр + схлопывание пробелов + trim', function () {
    expect(CategoryMatcher::normalize('  Кафе   и   Рестораны  '))
        ->toBe('кафе и рестораны');
});

test('keywordsTokens: разбивает keywords на нормализованные синонимы', function () {
    expect(CategoryMatcher::keywordsTokens('Аптека  Лекарство'))
        ->toBe(['аптека', 'лекарство'])
        ->and(CategoryMatcher::keywordsTokens(null))->toBe([])
        ->and(CategoryMatcher::keywordsTokens('   '))->toBe([]);
});

test('точное совпадение по title возвращает категорию с id и каноничным title', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Аптеки', 'keywords' => 'Аптеки']);

    $matched = $this->matcher->findForUser($this->user->id, 'Аптеки');

    expect($matched)->not->toBeNull()
        ->id->toBe($cat->id)
        ->title->toBe('Аптеки');
});

test('точное совпадение нечувствительно к регистру и лишним пробелам', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    expect($this->matcher->findForUser($this->user->id, '  КАФЕ И РЕСТОРАНЫ  '))
        ->not->toBeNull()->id->toBe($cat->id);
});

test('совпадение по синониму из keywords', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Аптеки', 'keywords' => 'аптека лекарство']);

    // ввод «Лекарство» матчится через keyword-токен
    expect($this->matcher->findForUser($this->user->id, 'Лекарство'))
        ->not->toBeNull()->id->toBe($cat->id);
});

test('совпадение по подстроке: «Кафе» ⊂ «Кафе и рестораны»', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    expect($this->matcher->findForUser($this->user->id, 'Кафе'))
        ->not->toBeNull()->title->toBe('Кафе и рестораны');
});

test('совпадение по подстроке с другой стороны: «рестораны» ⊂ «Кафе и рестораны»', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    expect($this->matcher->findForUser($this->user->id, 'рестораны'))
        ->not->toBeNull()->id->toBe($cat->id);
});

test('similar_text: «Супермаркет» ≈ «Супермаркеты» (единственное/множественное)', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Супермаркеты', 'keywords' => '']);

    expect($this->matcher->findForUser($this->user->id, 'Супермаркет'))
        ->not->toBeNull()->id->toBe($cat->id);
});

test('нет совпадения — возвращает null', function () {
    Category::create(['user_id' => $this->user->id, 'title' => 'Аптеки', 'keywords' => 'аптека']);

    expect($this->matcher->findForUser($this->user->id, 'Такси'))->toBeNull();
});

test('короткая строка (<4) не матчится через подстроку — guard от ложных срабатываний', function () {
    // «АЗС» (3 символа) не должна подстрочно матчить «Такси»; similar_text тоже низкий
    Category::create(['user_id' => $this->user->id, 'title' => 'Такси', 'keywords' => '']);

    expect($this->matcher->findForUser($this->user->id, 'АЗС'))->toBeNull();
});

test('user-scoping: чужие категории не матчатся', function () {
    $other = User::factory()->create();
    Category::create(['user_id' => $other->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    expect($this->matcher->findForUser($this->user->id, 'Кафе'))->toBeNull();
});

test('точное совпадение приоритетнее подстроки: «Кафе и рестораны» не схлопывается в «Кафе»', function () {
    $short = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе', 'keywords' => '']);
    $long = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    // точное совпадение с длинным названием побеждает над подстрочным с коротким
    expect($this->matcher->findForUser($this->user->id, 'Кафе и рестораны'))
        ->not->toBeNull()->id->toBe($long->id);

    // и наоборот: «Кафе» точно совпадает с коротким
    expect($this->matcher->findForUser($this->user->id, 'Кафе'))
        ->not->toBeNull()->id->toBe($short->id);
});

test('findExactForUser: точное совпадение находится, fuzzy-варианты (подстрока/похожее) — нет', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);

    // точное — да
    expect($this->matcher->findExactForUser($this->user->id, 'Кафе и рестораны'))
        ->not->toBeNull()->id->toBe($cat->id);

    // регистр/пробелы — нормализованно точное, тоже да
    expect($this->matcher->findExactForUser($this->user->id, '  КАФЕ И РЕСТОРАНЫ  '))
        ->not->toBeNull()->id->toBe($cat->id);

    // подстрока/похожее — НЕТ (в этом и смысл маркера «+» / force_new)
    expect($this->matcher->findExactForUser($this->user->id, 'Кафе'))->toBeNull();
    expect($this->matcher->findExactForUser($this->user->id, 'Супермаркет'))->toBeNull();
});

test('matchExact: точное совпадение по коллекции — да, fuzzy (подстрока/похожее) — нет', function () {
    $cat = Category::create(['user_id' => $this->user->id, 'title' => 'Кафе и рестораны', 'keywords' => '']);
    $categories = Category::where('user_id', $this->user->id)->get(['id', 'title']);

    // точное (нормализованно, с пробелами/регистром) — да
    expect($this->matcher->matchExact('  КАФЕ И РЕСТОРАНЫ  ', $categories))
        ->not->toBeNull()->id->toBe($cat->id);

    // подстрока/похожее/пустая строка — нет (симметрично findExactForUser)
    expect($this->matcher->matchExact('Кафе', $categories))->toBeNull()
        ->and($this->matcher->matchExact('Супермаркет', $categories))->toBeNull()
        ->and($this->matcher->matchExact('', $categories))->toBeNull();
});
