<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('categories.index', [
            'title' => 'Категории',
            'categories' => Category::where('user_id', auth()->user()->id)->get()
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('categories.create', [
            'title' => 'Добавить категорию'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255|min:2',
        ]);

        Category::query()->create([
            'title' => $request->title,
            'user_id' => auth()->user()->id,
            'keywords' => $request->keywords ?? ''
        ]);

        return redirect()->route('categories.index')->with('success', 'Категория добавлена');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category): View
    {
        return view('categories.edit', [
            'title' => 'Изменить категорию',
            'category' => Category::query()->findOrFail($category->id),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255|min:2',
        ]);

        Category::query()->findOrFail($category->id)->update([
            'title' => $request->title,
            'keywords' => $request->keywords ?? ''
        ]);

        return redirect()->route('categories.index')->with('success', 'Изменения сохранены.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category): RedirectResponse
    {
        Category::query()->findOrFail($category->id)->delete();

        return redirect()->route('categories.index')->with('success', 'Категория удалена');
    }

    public function fillInDefaultValues(CategoryService $categoryService): RedirectResponse
    {
        if(Category::query()->where('user_id', auth()->user()->id)->count() > 0) {
            $message = ['error' => 'Значения по умолчанию уже заполнены'];
        } else {
            $categoryService->fillInDefaultValues();
            $message = ['success' => 'Значения по умолчанию заполнены'];
        }

        return redirect()->route('categories.index')->with($message);
    }

    public function changeImportant(Request $request)
    {
        $request->validate([
            'category_id' => 'required|integer',
        ]);

        $record = Category::where('id', $request->category_id)
            ->firstOrFail();

        $record->is_important = !$record->is_important;
        $record->save();

        return response()->json([
            'status' => 'ok',
            'is_important' => $record->is_important
        ]);
    }
}
