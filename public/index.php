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
use Illuminate\Support\Arr;
use App\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use DiDom\Document;

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
$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, "index.phtml");
})->setName('main');

$router = $app->getRouteCollector()->getRouteParser();

$app->post('/urls', function ($request, $response) use ($router) {
    //валидация url
    $url = $request->getParsedBodyParam('url');
    $validator = new Validator();
    $errors = $validator->validate($url);

    if (!empty($errors)) {
        $params = ['errors' => $errors];
        return $this->get('renderer')->render($response->withStatus(422), "index.phtml", $params);
    }

    //проверка на нахождение в базе
    $name = strtolower($url['name']);
    $parsedUrl = parse_url($name);
    $urlData = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    //dump($urlData);
    $stm = $this->get('pdo')->prepare('SELECT * FROM urls WHERE name = :name');
    $stm->bindParam(':name', $name, PDO::PARAM_STR);
    $stm->execute();
    $urlExists = $stm->fetchColumn();
    if ($urlExists) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($router->urlFor('showUrl', ['id' => $urlExists]), 301);
    } else {
    // добавление в базу
        $sth = $this->get('pdo')->prepare('INSERT INTO urls (name, created_at) VALUES (?,?)');
        $sth->execute([$name, Carbon::now()->toDateTimeString()]);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        $id = $this->get('pdo')->lastInsertId();
        return $response->withRedirect($router->urlFor('showUrl', ['id' => $id]), 301);
    }
});

$app->get('/urls', function ($request, $response) {
    $sth = $this->get('pdo')->query("SELECT urls.id, urls.name, url_checks.status_code as code, 
                                     max(url_checks.created_at) as last
                                     FROM urls
                                     LEFT JOIN url_checks
                                     ON urls.id = url_checks.url_id
                                     GROUP BY urls.id, code;
                          ")->fetchAll();
    $urlsData = collect($sth)->toArray();
    $params = ['urlsData' => $urlsData];
    return $this->get('renderer')->render($response, "urls.phtml", $params);
})->setName('urlsData');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $urlId = $args['id'];
    $url = $this->get('pdo')->query("SELECT * FROM urls WHERE id = $urlId")->fetchAll();
    if ($url === null) {
        return $response->withStatus(404)
                        ->withHeader('Content-Type', 'text/html')
                        ->write('Url not found (:');
    }
    $checkedUrl = $this->get('pdo')->query("SELECT * FROM url_checks WHERE url_id = $urlId")->fetchAll();
    $messages = $this->get('flash')->getMessages();
    $params = ['url' => $url,
               'flash' => $messages,
               'checkedUrl' => $checkedUrl];
    return $this->get('renderer')->render($response, 'showUrl.phtml', $params);
})->setName('showUrl');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $id = $args['id'];
    $sth = $this->get('pdo')->query("SELECT * FROM urls WHERE id = $id")->fetchAll();
    $client = new Client(['timeout'  => 3.0]);
    try {
        $check = $client->get($sth[0]['name']);
        $this->get('flash')->addMessage('success', 'Страница проверена');
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Неизвестная ошибка, не удалось подключиться');
        return $response->withRedirect($router->urlFor('showUrl', ['id' => $id]), 301);
    } catch (RequestException $e) {
        $check = $e->getResponse();
        $this->get('flash')
             ->addMessage('warning', 'при выполнении проверки могли произойти ошибки, проверьте результаты');
    }

    $code = $check->getStatusCode();
    $html = $check->getBody()->getContents();
    $doc = new Document($html);
    $h1 = $doc->first('h1')->text();
    $title = $doc->first('title')->text();
    $content = $doc->first('meta[name=description]')->getAttribute('content');
    $nowTime = Carbon::now()->toDateTimeString();

    $urlChecks = $this->get('pdo')
                      ->prepare(query:'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
                                        VALUES (?,?,?,?,?,?)');
    $urlChecks->execute([$id, $code, $h1, $title, $content, $nowTime]);
    return $response->withRedirect($router->urlFor('showUrl', ['id' => $id]), 301);
});

$app->run();
