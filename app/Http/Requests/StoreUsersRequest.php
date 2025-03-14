<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|max:255',
            'email' => 'required|max:255|email',
            'password' => 'required|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Поле "ФИО" обязательно для заполнения',
            'name.min' => 'Поле "ФИО" должно быть не менее 2 символов',
            'name.max' => 'Поле "ФИО" должно быть не больше 255 символов',
            'email.required' => 'Поле "Email" обязательно для заполнения',
            'password.required' => 'Поле "Пароль" обязательно для заполнения',
            'password.confirmed' => 'Поля "Пароль" и "Подтверждение пароля" должны совпадать',
        ];
    }
}
