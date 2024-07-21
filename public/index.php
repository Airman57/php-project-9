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

$container->set('pdo', function() {
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
    $name = $url['name'];    
    $urlCheck = $this->get('pdo')->prepare('SELECT * FROM urls WHERE name = :name');
    $urlCheck->bindParam(':name', $name, PDO::PARAM_STR);
    $urlCheck->execute();
    $result = ['id' => $urlCheck->fetchColumn(), 'name' => $urlCheck->fetchColumn(1)];
    if ($name === $result['name']) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($router->urlFor('showUrl', ['id' => $result['id']]), 301);
    }

    // добавление в базу
    $sth = $this->get('pdo')->prepare(query: 'INSERT INTO urls (name, created_at) VALUES (?,?)');
    $sth->execute([$url['name'], Carbon::now()->toDateTimeString()]);
    $id = $this->get('pdo')->lastInsertId();
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');    
    return $response->withRedirect($router->urlFor('showUrl', ['id' => $id]), 301);
});

$app->get('/urls', function ($request, $response) {
    $sth = $this->get('pdo')->query("SELECT urls.id, urls.name, max(url_checks.created_at) as last
                                     FROM urls
                                     INNER JOIN url_checks
                                     ON urls.id = url_checks.url_id
                                     GROUP BY urls.id;
                          ")->fetchAll();
    $urlsData = collect($sth)->toArray();
    dump($urlsData);
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

$app->post('/urls/{id}/checks', function($request, $response, $args) use ($router) {
    $id = $args['id'];
    $sth = $this->get('pdo')->query("SELECT * FROM urls WHERE id = $id")->fetchAll();
    $urlChecks = $this->get('pdo')->prepare(query: 'INSERT INTO url_checks (url_id, created_at) VALUES (?,?)');
    $urlChecks->execute([$sth[0]['id'], Carbon::now()->toDateTimeString()]);
    return $response->withRedirect($router->urlFor('showUrl', ['id' => $id]), 301);
});

$app->run();