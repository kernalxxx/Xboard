<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'remind_expire' => 'in:0,1',
            'remind_traffic' => 'in:0,1',
            'remind_ticket' => 'in:0,1'
        ];
    }

    public function messages()
    {
        return [
            'remind_expire.in' => __('Incorrect format of expiration reminder'),
            'remind_traffic.in' => __('Incorrect traffic alert format'),
            'remind_ticket.in' => __('Incorrect format of ticket reminder')
        ];
    }
}
