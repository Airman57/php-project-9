<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

//$app->get('/', function (Request $request, Response $response, $args) {
    //$response->getBody()->write("Hello World!");
    //return $response;
//});

$app->get('/', function ($request, $response, $args) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    return $renderer->render($response, "index.phtml", $args);
});


$app->run();