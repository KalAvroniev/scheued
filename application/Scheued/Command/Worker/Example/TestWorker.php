<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 3/09/14
 * Time: 10:51 PM
 */
namespace Scheued\Command\Worker\Example;

use Scheued\Command\AbstractWorker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once '/home/vhosts/sites/nameinvestors.com/Application/bootstrap.php';

class TestWorker extends AbstractWorker
{
    protected function configure()
    {
        $this->setDescription('This is an example worker');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $params = parent::execute($input, $output);
        // This is where we do some execution logic
        $this->_returnRandomCompletion($params, $output);
        // call a decider
        $this->_addRequest(
            $this->_config['url'] . 'decider/' . strtolower(basename(__DIR__)),
            array('task-list' => $this->_taskList, 'async' => true)
        )->_swfActivityCall();
    }

    protected function _returnRandomCompletion($params, OutputInterface $output)
    {
        $this->_complete($params);
        $output->writeln(json_encode($params));
    }
} 