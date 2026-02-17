<?php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Firebase\JWT\JWT;
use Tuupola\Base62;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use DI\Container;
use Slim\Routing\RouteContext;
use JimTools\JwtAuth\Secret;
use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Rules\RequestPathRule;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Exceptions\AuthorizationException;

error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set("memory_limit", "-1");
date_default_timezone_set('UTC');
header('Content-Type: application/json');
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/index.php';
require __DIR__ . '/define.php';
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: *');
header("Access-Control-Allow-Headers: *");
/**
 * Instantiate App
 *
 * In order for the factory to work you need to ensure you have installed
 * a supported PSR-7 implementation of your choice e.g.: Slim PSR-7 and a supported
 * ServerRequest creator (included with Slim PSR-7)
 */
spl_autoload_register(function ($src) {
	if (file_exists('src/' . str_replace('\\', '/', $src) . '.php')) {
		require 'src/' . str_replace('\\', '/', $src) . '.php';
	}
});

class outer extends Container
{
	public function public_out($res, $action, $args)
	{
		$args['public'] = true;
		$action = str_replace('/', '___', $action);
		$ans = $this->get('handler')->$action($args);
		$res = $res->withStatus($ans->getstatus());
		$res->getBody()->write($ans->getdata());
		return $res;
	}
	public function out($res, $action, $args)
	{
		$args['public'] = false;
		$action = str_replace('/', '___', $action);
		$ans = $this->get('handler')->$action($args);
		$res = $res->withStatus($ans->getstatus());
		$res->getBody()->write($ans->getdata());
		return $res;
	}
}
$host = DBHOST;
$dbname = DBNAME;
$user = DBUSER;
$pass = DBPASS;
$pdo = new PDO("mysql:host=" . $host . ";dbname=" . $dbname . ';charset=UTF8', $user, $pass);
$db = new \Envms\FluentPDO\Query($pdo);
$container = new outer();
$container->set('handler', function () use ($db, $pdo) {
	require_once 'handle.php';
	return new handler($db, $pdo);
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath("/genie-api/");
$logger = new Logger("slim");
$errorMiddleware = $app->addErrorMiddleware(true, true, true, $logger);

// Add Routing Middleware

if (JWT_LOG == 'on') {
	$rotating = new RotatingFileHandler(__DIR__ . "/apiauthlogs/slim.log", 0, Logger::DEBUG);
	$logger->pushHandler($rotating);
}
$app->addRoutingMiddleware();
$app->add(function (Request $request, RequestHandler $handler) {
	try {
		$secret = new Secret(JWT_SECRET, 'HS256');
		$decoder = new FirebaseDecoder($secret);
		$options = new Options(
			isSecure: false,
			attribute: 'token'
		);
		$rule = new RequestPathRule(
			ignore: ['/genie-api/token', '/genie-api/cron/*']
		);

		$auth = new JwtAuthentication($options, $decoder, [$rule]);
		return $auth->process($request, $handler);
	} catch (AuthorizationException $e) {
		$data["status"] = false;
		$data["message"] = $e->getMessage();

		$response = new Response();
		$response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
		return $response->withHeader("Content-Type", "application/json")->withStatus(401);
	}
});

/**
* Add Error Handling Middleware
*
* @param bool $displayErrorDetails -> Should be set to false in production
* @param bool $logErrors -> Parameter is passed to the default ErrorHandler
* @param bool $logErrorDetails -> Display error details in error log
* which can be replaced by a callable of your choice.

* Note: This middleware should be added last. It will not handle any exceptions/errors
* for middleware added after it.
*/


$app->add(function (Request $request, RequestHandler $handler) use ($db) {
	$response = $handler->handle($request);
	if (API_LOG == 'on') {
		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();


		if ($route) {
			$name = $route->getPattern();
			if ($name == 'general/fileupload') {
				$requestjson = json_encode($_FILES);
			} else {
				$requestjson = $request->getBody();
			}
			$header = json_encode($request->getHeaders());
			$requestmethod = $request->getMethod();
			$responsejson = $response->getBody();
			$statuscode = $response->getStatusCode();
		} else {
			$requestjson = '';
			$requestmethod = '';
			$responsejson = '';
			$statuscode = '';
		}

		$ipaddress = '';
		if (isset($_SERVER['HTTP_CLIENT_IP']))
			$ipaddress = $_SERVER['HTTP_CLIENT_IP'];
		else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
			$ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
		else if (isset($_SERVER['HTTP_X_FORWARDED']))
			$ipaddress = $_SERVER['HTTP_X_FORWARDED'];
		else if (isset($_SERVER['HTTP_FORWARDED_FOR']))
			$ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
		else if (isset($_SERVER['HTTP_FORWARDED']))
			$ipaddress = $_SERVER['HTTP_FORWARDED'];
		else if (isset($_SERVER['REMOTE_ADDR']))
			$ipaddress = $_SERVER['REMOTE_ADDR'];
		else
			$ipaddress = 'UNKNOWN';
		// $curl = curl_init();
		// curl_setopt_array($curl, array(
		// CURLOPT_URL => "http://api.ipstack.com/".$ipaddress."?access_key=0b2a30a7491ac8840054d33623a48f71",
		// CURLOPT_RETURNTRANSFER => true,
		// CURLOPT_ENCODING => "",
		// CURLOPT_MAXREDIRS => 10,
		// CURLOPT_TIMEOUT => 0,
		// CURLOPT_FOLLOWLOCATION => true,
		// CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		// CURLOPT_CUSTOMREQUEST => "GET",
		// CURLOPT_HTTPHEADER => array(),
		// ));
		// $geo_details = curl_exec($curl);
		//curl_close($curl);
		$values['request'] = $requestjson;
		$values['method'] = $requestmethod;
		$values['header'] = $header;
		$values['url'] = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
		$values['response'] = $responsejson;
		$values['status'] = $statuscode;
		//$values['geo_details'] = $geo_details;
		$values['datetime'] = date('Y-m-d H:i:s');
		$values['user'] = $this->get('handler')->getuserid();
		$db->insertinto('api_logs')->values($values)->execute();
	}
	return $response;
});
$app->addRoutingMiddleware();
$app->get('token', function (Request $request, Response $response, $args) {
	$now = new DateTime();
	$future = new DateTime("now +100 hours");
	$tuupola = new Tuupola\Base62();
	$secret = JWT_SECRET;
	$payload = ["jti" => $tuupola->encode(random_bytes(16)), "iat" => $now->getTimeStamp(), "exp" => $future->getTimeStamp()];
	$token = JWT::encode($payload, $secret, "HS256");
	$response->getBody()->write(json_encode(['token' => $token]));
	return $response;
});

$app->options('{routes:.*}', function ($request, $res, $args) {
	$res->withStatus(200);
	$res->getBody()->write(json_encode(['status' => true]));
	return $res;
});
require __DIR__ . '/routes.php';
foreach ($public as $key => $route) {
	$app->post($route[0], function (Request $req, $res, $args) use ($route) {
		return $this->public_out($res, $route[1], $args);
	});
}
foreach ($publicget as $key => $route) {
	$app->get($route[0], function (Request $req, $res, $args) use ($route) {
		return $this->public_out($res, $route[1], $args);
	});
}
foreach ($post as $key => $route) {
	$app->post($route[0], function (Request $req, $res, $args) use ($route) {
		return $this->out($res, $route[1], $args);
	});
}
foreach ($get as $key => $route) {
	$app->get($route[0], function (Request $req, $res, $args) use ($route) {
		return $this->out($res, $route[1], $args);
	});
}

$app->post('{route:.*}', function (Request $req, $res, $args) {
	return $this->out($res, 'route404', $args);
});
$app->get('{route:.*}', function (Request $req, $res, $args) {
	return $this->out($res, 'route404', $args);
});
$app->run();
