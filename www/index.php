<?php

use UrlShortner\Handlers\HttpErrorHandler;
use UrlShortner\Handlers\ShutdownHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Slim\Factory\ServerRequestCreatorFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

// Controllers
use UrlShortner\Controllers\URLController;
use UrlShortner\Controllers\DatabaseController;

//Exceptions
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use UrlShortner\Exceptions\InvalidContentTypeException;
use UrlShortner\Exceptions\CustomException;

require __DIR__ . '/vendor/autoload.php';

$logger = new Logger('SimpleLogger');
$logger->pushHandler(new StreamHandler('./logs/php/server.log', Logger::DEBUG));

// Set that to your needs
$displayErrorDetails = true;
$app = AppFactory::create();

$app->addBodyParsingMiddleware();

$twig = Twig::create('views', ['cache_enabled' => false]);

$app->add(TwigMiddleware::create($app, $twig));

$callableResolver = $app->getCallableResolver();
$responseFactory = $app->getResponseFactory();

$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

$errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);
$shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
register_shutdown_function($shutdownHandler);

// Add Error Handling Middleware
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$errorMiddleware->setDefaultErrorHandler($errorHandler);

$app->get('/app[/]', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'index.html');
})->setName('home');

$app->get('/app/{url_hook}/stats', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);

    $urlHook = $args['url_hook'];

    $shortenedUrlController = new URLController($request);
    $viewData = $shortenedUrlController->getURLStats($urlHook);

    return $view->render($response, 'stats.twig',$viewData);
})->setName('home');

$app->post('/api[/]', function (Request $request, Response $response) use($app) {
    if (strpos('application/json', $request->getHeaderLine("Content-Type")) === false) {
        throw new InvalidContentTypeException($request);
    }

    $shortenedUrlController = new URLController($request);

    $inputData = $request->getParsedBody();

    $returnStatus = 201;
    $returnData = array();
    if(is_array($inputData) && array_keys($inputData)===range(0,count($inputData)-1)) {
        $data = $shortenedUrlController->createNewLink($inputData);
        if(count($inputData)==($data["processedCount"]+$data["errorCount"])){
            if($data["errorCount"]>0) $returnStatus = 207;
            if($data["errorCount"] == count($inputData)) $returnStatus = 400;
        }
        $returnData = $data["data"];
    } else {
        $inputData = array($inputData);
        $data = $shortenedUrlController->createNewLink($inputData);
        if(count($inputData)==($data["processedCount"]+$data["errorCount"])){
            if($data["errorCount"]>0) $returnStatus = 207;
            if($data["errorCount"] == count($inputData)) $returnStatus = 400;
        }
        $returnData = $data["data"][0];
    }

    $returnData = json_encode($returnData, JSON_PRETTY_PRINT);

    $response->getBody()->write($returnData);

    return $response->withHeader('Content-Type', 'application/json')->withStatus($returnStatus);
});

$app->get('/api/{url_hook}/stats[/]', function (Request $request, Response $response, $args) {
    try{
        $urlHook = $args['url_hook'];

        $shortenedUrlController = new URLController($request);

        if($shortenedUrlController->checkIfHookExists($urlHook)){
            $urlDetails = $shortenedUrlController->getURLStats($urlHook);
        }else{
            throw new HttpNotFoundException($request);
        }

        $returnData = json_encode($urlDetails, JSON_PRETTY_PRINT);

        $response->getBody()->write($returnData);

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    }catch(Exception $e){
        throw new HttpNotFoundException($request,$e->getMessage(),$e);
    }
});

$app->delete('/api/{url_hook}', function (Request $request, Response $response, $args) use($app) {

    $urlHook = $args['url_hook'];

    $shortenedUrlController = new URLController($request);

    $returnStatus = 200;
    $returnData = array();

    $data = $shortenedUrlController->removeURL($urlHook);
    if(($data["processedCount"]+$data["errorCount"]) == 1){
        if($data["errorCount"] > 0) $returnStatus = 400;
    }
    $returnData = $data["data"];

    $returnData = json_encode($returnData, JSON_PRETTY_PRINT);

    $response->getBody()->write($returnData);

    return $response->withHeader('Content-Type', 'application/json')->withStatus($returnStatus);
});

$app->any('/{url_hook}[/]', function (Request $request, Response $response, $args) {

    $urlHook = $args['url_hook'];

    $shortenedUrlController = new URLController($request);
    $urlDetails = $shortenedUrlController->getURLDetails($urlHook);

    //Redirecting
    if(isset($urlDetails) && count($urlDetails)>0 && isset($urlDetails["original_url"])){
        if(isset($urlDetails["expiration_date"]) && time()-strtotime($urlDetails["expiration_date"])>0){
            throw new CustomException($request, "Shortened URL Expired","The requested URL no longer in action.", 410, "EXPIRED_URL","EXPIRED_URL");
        }
        $shortenedUrlController->addURLStats($request,$urlDetails["id"]);
        $shortenedUrlController->getValidRedirectURL($urlDetails["original_url"]);

        return $response
            ->withHeader('Location', $urlDetails["original_url"])
            ->withStatus(302);
    }else{
        throw new HttpNotFoundException($request);
    }
});


$app->run();