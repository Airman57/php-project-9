<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\PhpRenderer;
use Carbon\Carbon;
use DI\Container;
use App\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use DiDom\Document;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

session_start();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeload();

$container = new Container();

AppFactory::setContainer($container);

$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('pdo', function () {
    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    $dbName = ltrim($databaseUrl['path'], '/');
    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $databaseUrl['host'],
        $databaseUrl['port'],
        $dbName,
        $databaseUrl['user'],
        $databaseUrl['pass']
    );

    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $pdo;
});

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);
$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, "index.phtml");
})->setName('main');

$app->post('/urls', function ($request, $response) use ($router) {
    //валидация url
    $url = $request->getParsedBodyParam('url');
    $validator = new Validator();
    $errors = $validator->validate($url);

    if (!empty($errors)) {
        $params = ['errors' => $errors,
                   'url' => $url];
        return $this->get('renderer')->render($response->withStatus(422), "index.phtml", $params);
    }

    //проверка на нахождение в базе
    $name = mb_strtolower($url['name']);
    $parsedUrl = parse_url($name);
    $urlData = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    $stm = $this->get('pdo')->prepare('SELECT * FROM urls WHERE name = :name');
    $stm->bindParam(':name', $name, PDO::PARAM_STR);
    $stm->execute();
    $urlExists = $stm->fetchColumn();
    if ($urlExists) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($router->urlFor('url.show', ['id' => $urlExists]), 301);
    } else {
    // добавление в базу
        $sth = $this->get('pdo')->prepare('INSERT INTO urls (name, created_at) VALUES (?,?)');
        $sth->execute([$name, Carbon::now()->toDateTimeString()]);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        $id = $this->get('pdo')->lastInsertId();
        return $response->withRedirect($router->urlFor('url.show', ['id' => $id]), 301);
    }
})->setName('urls.store');

$app->get('/urls', function ($request, $response) {
    $urlsCheckData = $this->get('pdo')->query("SELECT DISTINCT ON (url_id) url_id, created_at, status_code as code
                                               FROM url_checks
                                               ORDER BY url_id, created_at DESC;")->fetchAll();
    $urlKeys = collect((array) $urlsCheckData)->keyBy('url_id')->keys()->toArray();
    $in = implode(',', $urlKeys);
    $urlNames = $this->get('pdo')->query("SELECT name FROM urls
                                          WHERE id IN ($in)
                                          ORDER BY id")->fetchAll();
    $urlsData = array_map('array_merge', $urlsCheckData, $urlNames);
    $params = ['urlsData' => $urlsData];
    return $this->get('renderer')->render($response, "urls.phtml", $params);
})->setName('urls.index');

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) {
    $urlId = $args['id'];

    try {
        $url = $this->get('pdo')->query("SELECT * FROM urls WHERE id = $urlId")->fetchAll();
        if (empty($url)) {
            return $response->withStatus(404)
                        ->withHeader('Content-Type', 'text/html')
                        ->write('Url not found (:');
        }
    } catch (PDOException $e) {
        return $response->withStatus(404)
                        ->withHeader('Content-Type', 'text/html')
                        ->write('Url not found (:');
    }
    $checkedUrl = $this->get('pdo')->query("SELECT * FROM url_checks 
                                            WHERE url_id = $urlId 
                                            ORDER BY created_at DESC")
                                    ->fetchAll();
    $messages = $this->get('flash')->getMessages();
    $params = ['url' => $url,
               'flash' => $messages,
               'checkedUrl' => $checkedUrl];
    return $this->get('renderer')->render($response, 'showUrl.phtml', $params);
})->setName('url.show');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $id = $args['id'];
    $sth = $this->get('pdo')->query("SELECT * FROM urls WHERE id = $id")->fetchAll();
    $client = new Client(['timeout'  => 3.0]);
    try {
        $check = $client->get($sth[0]['name']);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Неизвестная ошибка, не удалось подключиться');
        return $response->withRedirect($router->urlFor('url.show', ['id' => $id]), 301);
    } catch (RequestException $e) {
        $check = $e->getResponse();
        $code = optional($check)->getStatusCode();
        $html = optional($check)->getBody()->getContents();
        $doc = new Document($html);
        $h1Data = optional($doc->first('h1'))->text();
        if ($h1Data === null) {
            $h1 = '';
        } else {
            $h1 = Str::limit($h1Data, 252, '...');
        }
        $title = optional($doc->first('title'))->text();
        $contentData = optional($doc->first('meta[name=description]'))->getAttribute('content');
        $content = Str::limit($contentData, 252, '...');
        $nowTime = Carbon::now()->toDateTimeString();

        $urlChecks = $this->get('pdo')
                        ->prepare(query:'INSERT INTO url_checks 
                                        (url_id, status_code, h1, title, description, created_at) 
                                        VALUES (?,?,?,?,?,?)');
        $urlChecks->execute([$id, $code, $h1, $title, $content, $nowTime]);
        $this->get('flash')
             ->addMessage('warning', 'При выполнении запроса пришел неоднозначный ответ.
                                      Возможно для нашего ip-адреса проверка заблокирована');
        return $response->withRedirect($router->urlFor('url.show', ['id' => $id]), 301);
    }

    $code = optional($check)->getStatusCode();
    $html = optional($check)->getBody()->getContents();
    $doc = new Document($html);
    $h1Data = optional($doc->first('h1'))->text();
    if ($h1Data === null) {
        $h1 = '';
    } else {
        $h1 = Str::limit($h1Data, 252, '...');
    }
    $title = optional($doc->first('title'))->text();
    $contentData = optional($doc->first('meta[name=description]'))->getAttribute('content');
    $content = Str::limit($contentData, 252, '...');
    $nowTime = Carbon::now()->toDateTimeString();

    $urlChecks = $this->get('pdo')
                      ->prepare(query:'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
                                        VALUES (?,?,?,?,?,?)');
    $urlChecks->execute([$id, $code, $h1, $title, $content, $nowTime]);
    return $response->withRedirect($router->urlFor('url.show', ['id' => $id]), 301);
})->setName('urls.check');

$app->run();
