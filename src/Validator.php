<?php

namespace App;

class Validator
{
    public function validate(array $url)
    {
        $errors = '';
        if ($url['name'] === "") {
            $errors = 'Url не может быть пустым';            
        } elseif (!filter_var($url['name'], FILTER_VALIDATE_URL)) {
            $errors = 'Некорректный URL';
        } 
        return $errors;
    }
}