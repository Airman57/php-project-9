<?php

namespace App;

class Validator
{
    public function validate(array $url)
    {
        $errors = [];
        if ($url['name'] === "") {
            $errors = ['empty' => 'Url не может быть пустым'];
        } elseif (!preg_match("/^(https?:\/\/+[\w\-]+\.[\w\-]+)/i", $url['name'])) {
            $errors = ['wrongUrl' => 'Некорректный URL'];
        }
        return $errors;
    }
}
