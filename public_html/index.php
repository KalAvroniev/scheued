<?php
set_time_limit(60);
const APP_NAME    = 'Scheued';
const APP_VERSION = 0.1;
// Define path to application directory
defined('APP_PATH')
|| define('APP_PATH', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'application'));
// Define application environment
defined('APPLICATION_ENV')
|| define(
'APPLICATION_ENV',
(
getenv('APPLICATION_ENV')
    ? getenv('APPLICATION_ENV')
    : (
getenv('HOSTNAME') == 'development'
    ? 'development'
    : (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'staging.') !== false)
    ? 'staging'
    : 'production'
)
)
);
// Define path to application directory
defined('LIB_PATH')
|| define('LIB_PATH', APP_PATH . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor');
if (!$loader = include LIB_PATH . DIRECTORY_SEPARATOR . 'autoload.php') {
    die('You must set up the project dependencies.');
}
$webApp = new \Silex\Application();
// Init configuration
$configPath = APP_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.yml';
// Register list of commands
$finder       = new \Symfony\Component\Finder\Finder();
$commandsPath = APP_PATH . DIRECTORY_SEPARATOR . APP_NAME . DIRECTORY_SEPARATOR . 'Command' . DIRECTORY_SEPARATOR;
$iterator     = $finder->files()->depth('>0')->in($commandsPath);
foreach ($iterator as $file) {
    $path = $file->getPathInfo()->getPathname();
    preg_match('/(worker|decider|flow)(.*)/i', $path, $matches);
    $controller = _convertToUrlFormat($matches[1]);
    $fileName   = $file->getFilename();
    if(!empty($matches[2])) {
        $fileName = $matches[2] . $fileName;
    }
    $action     = _convertToUrlFormat(
        str_ireplace(array($controller, '.php', DIRECTORY_SEPARATOR), '', $fileName)
    );
    $class      = str_replace(array(APP_PATH, '.php', DIRECTORY_SEPARATOR), array('', '', '\\'), $file);
    $webApp->match(DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action, $class . '::render');
}
// Set up error handler
$webApp->error(
    function (Exception $e, $code) use ($webApp) {
        return $webApp->json(array('error' => $e->getMessage(), 'code' => $e->getCode()), $code);
    }
);
$webApp['debug'] = true;
$webApp->run();
function _convertToUrlFormat($string)
{
    return strtolower(preg_replace('/(.)([A-Z])/', '$1-$2', $string));
}