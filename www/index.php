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

// Setting a logger.
$logger = new Logger('SimpleLogger');
$logger->pushHandler(new StreamHandler('./logs/php/server.log', Logger::DEBUG));

// Setting this to true to display errors
$displayErrorDetails = true;
$app = AppFactory::create();

// Adding body parsing middleware as this is needed to parse the POST JSON body
$app->addBodyParsingMiddleware();

// Adding twig to have SLIM application display views
$twig = Twig::create('views', ['cache_enabled' => false]);
$app->add(TwigMiddleware::create($app, $twig));

// Adding custom error handler and shutdown handler
//Start
$callableResolver = $app->getCallableResolver();
$responseFactory = $app->getResponseFactory();

$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

$errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);
$shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
register_shutdown_function($shutdownHandler);

$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$errorMiddleware->setDefaultErrorHandler($errorHandler);
//End


$app->get('/app[/]', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'index.html');
})->setName('home');

$app->get('/app/{url_hook}/stats', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);

    $urlHook = $args['url_hook'];

    $shortenedUrlController = new URLController($request);
    try{
        $viewData = $shortenedUrlController->getURLStats($urlHook);
    }catch(Exception $e){
        // Do nothing
    }

    return $view->render($response, 'stats.twig',$viewData);
})->setName('stats');

$app->post('/api[/]', function (Request $request, Response $response) use($app) {
    // Checking if REQUEST content is JSON
    if (strpos('application/json', $request->getHeaderLine("Content-Type")) === false) {
        throw new InvalidContentTypeException($request);
    }

    // Initializing the URLController
    $shortenedUrlController = new URLController($request);

    // Getting the JSON body
    $inputData = $request->getParsedBody();

    //Setting default return status as 201
    $returnStatus = 201;
    $returnData = array();

    //Checking if array of URLs passed or just one object
    if(is_array($inputData) && array_keys($inputData)===range(0,count($inputData)-1)) {

        // Creating New URL/URLs
        $data = $shortenedUrlController->createNewLink($inputData);

        // Based on processedCount and errorCount determining the response status.
        if(count($inputData)==($data["processedCount"]+$data["errorCount"])){
            if($data["errorCount"]>0) $returnStatus = 207;
            if($data["errorCount"] == count($inputData)) $returnStatus = 400;
        }
        $returnData = $data["data"];
    } else {
        $inputData = array($inputData);
        $data = $shortenedUrlController->createNewLink($inputData);

        // Based on processedCount and errorCount determining the response status.
        if(count($inputData)==($data["processedCount"]+$data["errorCount"])){
            if($data["errorCount"]>0) $returnStatus = 207;
            if($data["errorCount"] == count($inputData)) $returnStatus = 400;
        }
        $returnData = $data["data"][0];
    }

    //Prettifying return JSON
    $returnData = json_encode($returnData, JSON_PRETTY_PRINT);

    // Writing response data to body
    $response->getBody()->write($returnData);

    //Returning with JSON type and preset status.
    return $response->withHeader('Content-Type', 'application/json')->withStatus($returnStatus);
});

$app->get('/api/{url_hook}/stats[/]', function (Request $request, Response $response, $args) {
    try{
        //Fetching the url_hook from request URL.
        $urlHook = $args['url_hook'];

        // Initializing the URLController
        $shortenedUrlController = new URLController($request);

        //Checking if the hook exists based on hook from URL, else throwing an error.
        if($shortenedUrlController->checkIfHookExists($urlHook)){

            // If hook exists, fetching the stats for that hook.
            $urlDetails = $shortenedUrlController->getURLStats($urlHook);
        }else{
            throw new HttpNotFoundException($request);
        }

        //Prettifying return JSON
        $returnData = json_encode($urlDetails, JSON_PRETTY_PRINT);

        // Writing response data to body
        $response->getBody()->write($returnData);

        //Returning with JSON type and status 200. Error case is taken care of by the exception above.
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }catch(Exception $e){
        throw new HttpNotFoundException($request,$e->getMessage(),$e);
    }
});

$app->delete('/api/{url_hook}', function (Request $request, Response $response, $args) use($app) {

    //Fetching the url_hook from request URL.
    $urlHook = $args['url_hook'];

    // Initializing the URLController
    $shortenedUrlController = new URLController($request);

    // Setting default response status to 200
    $returnStatus = 200;
    $returnData = array();

    // Removing the URL entry based on hook from DB
    $data = $shortenedUrlController->removeURL($urlHook);
    if(($data["processedCount"]+$data["errorCount"]) == 1){
        //errorCount>0 means the one url we passed to remove failed and $data will have the necessary status code.
        if($data["errorCount"] > 0) $returnStatus = 400;
    }
    $returnData = $data["data"];

    //Prettifying return JSON
    $returnData = json_encode($returnData, JSON_PRETTY_PRINT);

    //if the data returned has status code set, we use that. Errors have status code set.
    if(isset($data["data"]["statusCode"])) $returnStatus = $data["data"]["statusCode"];

    // Writing response data to body
    $response->getBody()->write($returnData);

    //Returning with JSON type and preset status.
    return $response->withHeader('Content-Type', 'application/json')->withStatus($returnStatus);
});

$app->any('/{url_hook}[/]', function (Request $request, Response $response, $args) {

    $urlHook = $args['url_hook'];

    // Initializing the URLController
    $shortenedUrlController = new URLController($request);

    //Fetching the URL details based on url_hook from request URL
    $urlDetails = $shortenedUrlController->getURLDetails($urlHook);

    //Redirecting if url details i not empty i.e. URL exists
    if(isset($urlDetails) && count($urlDetails)>0 && isset($urlDetails["original_url"])){

        // Checking if the URL is expired, if expired, throwing a custom exception with status 410
        if(isset($urlDetails["expiration_date"]) && time()-strtotime($urlDetails["expiration_date"])>0){
            throw new CustomException($request, "Shortened URL Expired","The requested URL no longer in action.", 410, "EXPIRED_URL","EXPIRED_URL");
        }

        // If the URl is not expired, related stats are added top DB.
        $shortenedUrlController->addURLStats($request,$urlDetails["id"]);

        //A valid redirect URL is fetched.
        $shortenedUrlController->getValidRedirectURL($urlDetails["original_url"]);

        // Redirection with status 302
        return $response
            ->withHeader('Location', $urlDetails["original_url"])
            ->withStatus(302);
    }else{
        throw new HttpNotFoundException($request);
    }
});


$app->run();