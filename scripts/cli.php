#!/usr/bin/env php
<?php
set_time_limit(60);
const APP_NAME = 'Scheued';
const APP_VERSION = 0.1;

// Define path to application directory
defined('APPLICATION_PATH')
|| define('APPLICATION_PATH', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'application'));

// Define path to application directory
defined('LIBRARY_PATH')
|| define('LIBRARY_PATH', APPLICATION_PATH . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor');

if (!$loader = include LIBRARY_PATH . DIRECTORY_SEPARATOR . 'autoload.php') {
    die('You must set up the project dependencies.');
}

$cliApp = new \Cilex\Application(APP_NAME, APP_VERSION);

// Init configuration
$configPath = APPLICATION_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.yml';

$cliApp->register(new \Cilex\Provider\ConfigServiceProvider(), array('config.path' => $configPath));

// Register list of commands
$finder = new \Symfony\Component\Finder\Finder();
$commandsPath = APPLICATION_PATH . DIRECTORY_SEPARATOR . APP_NAME . DIRECTORY_SEPARATOR . 'Command' . DIRECTORY_SEPARATOR;
$iterator = $finder->files()->depth('>0')->in($commandsPath);
foreach ($iterator as $file) {
    $command = str_replace(array(APPLICATION_PATH, '.php', DIRECTORY_SEPARATOR), array('', '', '\\'), $file);
    $cliApp->command(new $command());
}

// Add watchdog command
$cliApp->command(new Scheued\Command\WatchDog());

$cliApp->run();

