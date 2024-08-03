<?php

namespace App;

class Validator
{
    public function validate(array $url)
    {
        $errors = [];
        if ($url['name'] === "") {
            $errors = ['empty' => 'Url не может быть пустым'];            
        } elseif (!filter_var($url['name'], FILTER_VALIDATE_URL)) {
            $errors = ['wrongUrl' => 'Некорректный URL'];
        } 
        return $errors;
    }
}