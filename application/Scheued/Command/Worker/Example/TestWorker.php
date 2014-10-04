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

class TestWorker extends AbstractWorker {
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
            'http://development/scheued/public_html/decider/' . strtolower(basename(__DIR__)),
            array('task-list' => $this->_taskList, 'async' => true)
        )->_swfActivityCall();
    }

    protected function _returnRandomCompletion($params, OutputInterface $output) {
        $status = array('_cancel', '_complete', '_fail');
        $random = 1;

        switch ($status[$random]) {
            case '_cancel':
                $this->_cancel('No need to run this');
                $output->writeln(json_encode('No need to run this'));
                break;
            case '_complete':
                $this->_complete($params);
                $output->writeln(json_encode($params));
                break;
            case '_fail':
                $this->_fail('Something went wrong');
                $output->writeln(json_encode('Something went wrong'));
                break;
        }
    }
} 