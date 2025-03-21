<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'title',
        'user_id',
        'keywords'
    ];

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_category_cashback', 'category_id', 'card_id')
            ->withPivot('cashback_percentage', 'mcc', 'updated_at');
    }

    public function cashbacks(): BelongsToMany
    {
        return $this->belongsToMany(Cashback::class, 'card_category_cashback', 'category_id', 'cashback_id');
    }

    public static function fillInDefaultValues()
    {
        $categories = [
            'QR или NFS' => '',
            'Авто' => 'Автоуслуги запчасти',
            'АЗС' => 'бензин заправка топливо',
            'Аптеки' => 'лекарства Здоровье',
            'Все покупки' => '',
            'Детские товары' => 'Детский Мир Зоозавр',
            'Дом и ремонт' => 'строительство',
            'Животные и зоотовары' => 'Ветеринар товары для животных',
            'ЖКХ / ЖКУ' => 'квартплата коммунальные услуги',
            'Кафе и рестораны' => 'Столовая',
            'Косметика и парфюмерия' => '',
            'Красота и здоровье' => 'Салоны красоты и СПА',
            'Маркетплейсы' => 'Ozon Озон WB Wildberries ЯндексМаркет МегаМаркет',
            'Медицинские услуги' => 'Здоровье стоматология оптика',
            'Общественный транспорт' => 'автобус трамвай троллейбус метро маршрутка',
            'Одежда и Обувь' => '',
            'Оплата улыбкой' => '',
            'Связь и интернет' => 'мобильный сотовый провайдер',
            'Спорт и фитнесс' => 'бассейн',
            'Спортивные товары' => 'Спорттовары',
            'Супермаркеты' => 'еда продукты магнит пятерочка перекресток монетка лента',
            'Такси' => 'Яндекс Такси',
            'Техника и электроника' => 'бытовая техника Эльдорадо МВидео DNS',
            'Фастфуд' => 'Вкусно и Точка Бургер Кинг Ростикс KFC',
            'Ювелирные изделия' => 'золото серебро'
        ];

        foreach ($categories as $title => $keywords) {
            $category = new Category();
            $category->user_id = auth()->user()->id;
            $category->title = $title;
            $category->keywords = $keywords;
            $category->save();
        }
    }
}
