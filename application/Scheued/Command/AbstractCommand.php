<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 13/09/14
 * Time: 3:03 PM
 */
namespace Scheued\Command;

use Aws\Sns\SnsClient;
use Aws\Swf\SwfClient;
use Cilex\Command\Command;
use Doctrine\Common\Cache\FilesystemCache;
use Exception;
use Guzzle\Cache\DoctrineCacheAdapter;
use Silex\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

abstract class AbstractCommand extends Command
{
    /** @var JsonResponse */
    protected $_response = null;
    /** @var SwfClient */
    protected $_swfClient = null;
    protected $_snsClient = null;
    protected $_config;
    protected $_token = '';
    protected $_taskList = '';

    public function render(Request $request, Application $app)
    {
        try {
            $runAsync       = (bool)$request->query->get('async', false);
            $commandBuilder = new ProcessBuilder();
            $commandBuilder->setPrefix(
                APPLICATION_PATH . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'cli.php'
            )
                ->add(str_replace(DIRECTORY_SEPARATOR, ':', substr($request->getPathInfo(), 1)))
                ->add(APPLICATION_ENV);

            // Modify the command based on type
            $this->_render($request, $commandBuilder);

            $process = $commandBuilder->getProcess();
//            var_dump($process->getCommandLine()); exit;
            if ($runAsync) {
                $process->start();
                $this->_response = $app->json(array('success' => 'This is an async call'));
            } else {
                $object = $this;
                $process->mustRun(
                    function ($type, $buffer) use ($app, $object) {
                        call_user_func_array(array($object, 'getCommandResponse'), array($type, $buffer, $app));
                    }
                );
            }
        } catch (RuntimeException $e) {
            $this->getCommandResponse(Process::ERR, $e->getMessage(), $app, $e->getCode());
        }

        return $this->_response;
    }

    public function getCommandResponse($type, $buffer, $app, $code = 500)
    {
        if (Process::ERR === $type && $buffer != "\n") {
            throw new \Exception($buffer, $code);
        } else {
            $this->_response = $app->json(array('success' => json_decode($buffer, true)));
        }
    }

    /**
     * This is where we configure the command name, description and arguments or options
     * Added a default environment argument to tell which configuration to use
     */
    protected function configure()
    {
        $this->addArgument(
            'environment', InputArgument::REQUIRED | InputArgument::OPTIONAL, 'What environment to run the script in', 'production'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->_loadConfigByProfile($input, $output)) {
            // Create a cache adapter that stores data on the filesystem
            $old = umask(0); // need to reset the umask in order to be able to create folder wih 777 permissions
            $cacheAdapter = new DoctrineCacheAdapter(new FilesystemCache('/tmp/cache'));
            umask($old); // return to normal value
            // Provide a credentials.cache to cache credentials to the file system
            $this->_swfClient = SwfClient::factory(
                array(
                    'credentials.cache' => $cacheAdapter,
                    'region'            => 'us-east-1',
                    'key'               => $this->_config['aws_credentials']['key'],
                    'secret'            => $this->_config['aws_credentials']['secret']
                )
            );
            $this->_snsClient = SnsClient::factory(
                array(
                    'credentials.cache' => $cacheAdapter,
                    'region'            => 'us-east-1',
                    'key'               => $this->_config['aws_credentials']['key'],
                    'secret'            => $this->_config['aws_credentials']['secret']
                )
            );
        }
    }

    protected function _loadConfigByProfile(InputInterface $input, OutputInterface $output)
    {
        /** @var \Cilex\Application $app */
        $app         = $this->getContainer();
        $environment = $input->getArgument('environment');
        if (isset($app['config'][$environment])) {
            $this->_config = $app['config'][$environment];

            return true;
        } else {
            $this->_config = $app['config']['default'];
            $error         = "Unknown environment " . $environment . "\n";
            $output->writeln($error);

            return false;
        }
    }

    protected function _getClassName($type)
    {
        $class = get_class($this);
        preg_match('/' . $type . '.*?([^\\\\]*)' . $type . '/i', $class, $matches);

        return $type . ':' . lcfirst($matches[1]);
    }

    /**
     * Remove the type of task and return only task name
     *
     * @return mixed
     */
    protected function _getTaskName()
    {
        $name = explode(':', $this->getName());

        return $name[1];
    }

    /**
     * Converts the filters array to proper format
     *
     * @param array $options
     * Allowed formats:
     * <code>
     * --filter=key=value
     * --filter=key.subkey=value
     * </code>
     *
     * @return array
     */
    protected function _parseArrayOptions($options)
    {
        $tmp = array();
        foreach ($options as $option) {
            $keys  = explode('.', $option);
            $level = & $tmp;
            for ($i = 0; $i < count($keys) - 1; $i++) {
                if (!array_key_exists($keys[$i], $level)) {
                    $level[$keys[$i]] = array();
                }
                $level = & $level[$keys[$i]];
            }
            $keyVal            = explode('=', $keys[$i]);
            $level[$keyVal[0]] = $keyVal[1];
            unset($level);
        }

        return $tmp;
    }

    /**
     * Generates a random id
     *
     * @return string
     */
    protected function _generateId()
    {
        return str_replace('.', '', microtime(true));
    }

    abstract protected function _countPendingTasks(InputInterface $input);

    abstract protected function _render(Request $request, ProcessBuilder &$commandBuilder);
} 