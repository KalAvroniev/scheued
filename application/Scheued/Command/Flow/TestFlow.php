<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 14/09/14
 * Time: 12:32 PM
 */

namespace Scheued\Command\Flow;

use Scheued\Command\AbstractWorkflow;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestFlow extends AbstractWorkflow
{
    protected function configure()
    {
        $this->setDescription('This is a test workflow');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
    }
} 