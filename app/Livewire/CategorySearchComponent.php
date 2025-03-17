<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;
use Illuminate\Support\Collection;
use App\Models\Category;

class CategorySearchComponent extends Component
{
    public string $search = '';
    public Collection $products;
    public Collection $categories;
    public Collection $filteredProducts;

    public function mount(): void
    {
        $this->products = Category::query()->where('user_id', auth()->user()->id)->get();
        $this->filteredProducts = $this->products;
        $this->categories = Category::query()->where('user_id', auth()->user()->id)->get();
    }

    public function render(): View
    {
        return view('livewire.category-search-component', [
            'search' => $this->search,
        ]);
    }

    public function updatedSearch($value): void
    {
        if (!empty($value)) {
            $this->filteredProducts = $this->products
                ->filter(function ($category) use ($value) {
                    $normalizedTitle = mb_strtolower($category->title);
                    $normalizedKeywords = mb_strtolower($category->keywords ?? '');
                    $normalizedValue = mb_strtolower($value);

                    return (
                        stripos($normalizedTitle, $normalizedValue) !== false ||
                        stripos($normalizedKeywords, $normalizedValue) !== false
                    );
                });
        } else {
            $this->filteredProducts = $this->products;
        }
    }
}
