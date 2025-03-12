<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientsRequest;
use App\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ClientsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Client::class);

        $data = array();
        $data['title'] = 'Клиенты';
        $data['clients'] = Client::query()->paginate(5);

        return view('clients.index', $data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('clients.create', [
            'title' => 'Добавить клиента',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClientsRequest $request): RedirectResponse
    {
        $validate = $request->safe()->toArray();
        Client::query()->create($validate);

        return redirect()->route('clients.index')->with('success', 'Клиент добавлен');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client): View
    {
        return view('clients.edit', [
            'title' => 'Изменить клиента',
            'client' => Client::query()->findOrFail($client->id),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreClientsRequest $request, Client $client): RedirectResponse
    {
        $validate = $request->safe()->toArray();
        Client::query()->findOrFail($client->id)->update($validate);

        return redirect()->route('clients.index')->with('success', 'Изменения сохранены');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client): RedirectResponse
    {
        Client::destroy($client->id);

        return redirect()->route('clients.index')->with('success', 'Клиент удален');
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return Client::query()->where('id', $value)->firstOrFail();
    }
}
