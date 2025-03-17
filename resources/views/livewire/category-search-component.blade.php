<div class="card-body">

    <a href="{{ route('categories.create') }}" class="btn btn-primary mb-3">Добавить Категорию</a>

    <div class="form-group row">
        <div class="col-md-6">
            <input type="text" wire:model.live="search" class="form-control" placeholder="Поиск по названию категории...">
        </div>
    </div>

    @if($categories->count() === 0)
        <a href="{{route('categories.fill_default')}}" class="btn btn-outline-success mb-3 ml-1">Заполнить категориями по-умолчанию</a>

        <div class="alert alert-warning" role="alert">
            Вы можете заполнить стандартными категориями, нажав на кнопку "Заполнить категориями по-умолчанию"
        </div>
    @endif

    <div class="table-responsive">
        <table id="categories" class="table table-hover table-bordered">
            <thead class="thead-dark">
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Категория</th>
                <th scope="col">Ключевые слова для поиска</th>
                <th scope="col"></th>
            </tr>
            </thead>
            <tbody>
            @if ($filteredProducts->count())
            @foreach($filteredProducts as $category)
                <tr>
                    <th style="width: 50px"
                        class="edit_category {{$category->id}}"
                        data-toggle='modal' data-target='#modal'
                        scope="row">{{$loop->iteration}}</th>
                    <td class="edit_category {{$category->id}}"
                        data-toggle='modal'
                        data-target='#modal'>{{$category->title}}</td>
                    <td class="edit_category {{$category->id}}"
                        data-toggle='modal'
                        data-target='#modal'>{{$category->keywords}}</td>

                    <td style="width: 40px">
                        <div class="btn-group" role="group">
                            <a href="{{ route('categories.edit', ['category' => $category->id]) }}" class="btn btn-primary mr-1">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('categories.destroy', ['category' => $category->id]) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Вы уверены?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            @else
                <tr>
                    <td colspan="4" class="text-center">
                        Нет результатов.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

</div>
