<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginationComponent extends Component
{
    public LengthAwarePaginator $collection;

    /**
     * Create a new component instance.
     */
    public function __construct($collection)
    {
        $this->collection = $collection;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.pagination-component');
    }
}
