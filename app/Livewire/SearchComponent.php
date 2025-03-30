<?php

namespace App\Livewire;

use App\Services\CashbackService;
use Illuminate\View\View;
use Livewire\Component;

class SearchComponent extends Component
{
    public $user;
    public string $search = '';
    public $filteredCategoriesCashback;

    public bool $isLoading = false;

    public function mount($user): void
    {
        $this->filteredCategoriesCashback = CashbackService::getAllCardWhichHavePercent($user->id);
    }

    public function render(): View
    {
        return view('livewire.search-component');
    }

    public function updatedSearch($value): void
    {
        $this->isLoading = true;
        $this->filteredCategoriesCashback = CashbackService::getAllCardWhichHavePercent($this->user->id, $value);
        $this->isLoading = false;
    }
}
