<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 3/09/14
 * Time: 10:51 PM
 */

namespace Scheued\Command\Worker;

use Scheued\Command\AbstractWorker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestWorker extends AbstractWorker {
    protected function configure()
    {
        $this->setDescription('This is a test worker');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $params = parent::execute($input, $output);
        // This is where we do some execution logic
        $this->_returnRandomCompletion($params, $output);
//@todo will have to call a decider
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