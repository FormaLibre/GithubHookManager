<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use FormaLibre\Controller;

$locator = new FileLocator(array(__DIR__ . '/config'));
$requestContext = new RequestContext($_SERVER['REQUEST_URI']);
$loader = new YamlFileLoader($locator);
$routes = $loader->load('routes.yml');
$controller = new Controller();
$baseUrl = $requestContext->getBaseUrl();
$path = substr($baseUrl, strpos($baseUrl, "/hook.php") + 9);
$matcher = new UrlMatcher($routes, $requestContext);
$parameters = $matcher->match($path);
//I'll need to change that to make it less anoying and know the pattern beforehand
$controller = new Controller();
$callFuncParam = array($controller, 'execute');
unset($parameters['_route']);

return call_user_func_array($callFuncParam, $parameters);
