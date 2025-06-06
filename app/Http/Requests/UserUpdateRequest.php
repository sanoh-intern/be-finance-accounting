<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Exceptions\HttpResponseException;

class UserUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::user()->role == 1;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id'); // Get the user ID from the route parameter

        return [
            'bp_code' => 'nullable|string|max:25',
            'name' => 'nullable|string|max:25',
            'role' => 'nullable|string|max:25',
            'password' => 'nullable|string|min:8',
            'username' => 'nullable|string|max:25|unique:user,username,' . $userId . ',user_id',
            'email' => 'nullable|email|max:255'
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bp_code.string' => 'The BP code must be a string.',
            'bp_code.max' => 'The BP code may not be greater than 25 characters.',
            'name.string' => 'The name must be a string.',
            'name.max' => 'The name may not be greater than 25 characters.',
            'role.string' => 'The role must be a string.',
            'role.max' => 'The role may not be greater than 25 characters.',
            'password.string' => 'The password must be a string.',
            'password.min' => 'The password must be at least 8 characters.',
            'username.string' => 'The username must be a string.',
            'username.unique' => 'The username has already been taken.',
            'username.max' => 'The username may not be greater than 25 characters.',
            'email.email' => 'The email must be a valid email address.',
            'email.max' => 'The email may not be greater than 255 characters.',
        ];
    }

    // Failed validation response
    protected function failedValidation($validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
