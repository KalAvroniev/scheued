<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 2/09/14
 * Time: 11:20 PM
 */
namespace Scheued\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\ProcessBuilder;

abstract class AbstractWorker extends AbstractCommand
{
    const TYPE = 'worker';

    /**
     * This is where we configure the command name, description and arguments or options
     */
    protected function configure()
    {
        parent::configure();

        $name = $this->_getClassName(AbstractWorker::TYPE);
        $this->setName($name)
            ->setProcessTitle($name)
            ->addArgument(
                'task-list',
                InputArgument::OPTIONAL, 'Used to specify the task list if we are not using the default',
                $this->_getTaskName()
            );;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return null|string|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_taskList = $input->getArgument('task-list');
        parent::execute($input, $output);
        if ($this->_countPendingTasks($input)) {
            $result       = $this->_swfClient->pollForActivityTask(
                array(
                    'domain'   => $this->_config['swf']['domain'],
                    'taskList' => array(
                        'name' => $this->_taskList,
                    ),
                    'identity' => $this->getName()
                )
            );
            $this->_token = $result['taskToken'];

            return isset($result['input']) ? $result['input'] : '';
        }

        return '';
    }

    /**
     * Used by workers to tell the service that the ActivityTask identified by the taskToken was successfully canceled
     *
     * @param $details
     */
    protected function _cancel($details)
    {
        $this->_swfClient->respondActivityTaskCanceled(
            array(
                'taskToken' => $this->_token,
                'details'   => $details
            )
        );
    }

    /**
     * Used by workers to tell the service that the ActivityTask identified by the taskToken completed successfully
     * with a result (if provided)
     *
     * @param $result
     */
    protected function _complete($result)
    {
        $this->_swfClient->respondActivityTaskCompleted(
            array(
                'taskToken' => $this->_token,
                'result'    => $result
            )
        );
    }

    /**
     * Used by workers to tell the service that the ActivityTask identified by the taskToken has failed
     * with reason (if specified)
     *
     * @param        $reason
     * @param string $details
     */
    protected function _fail($reason, $details = '')
    {
        $this->_swfClient->respondActivityTaskFailed(
            array(
                'taskToken' => $this->_token,
                'reason'    => $reason,
                'details'   => $details
            )
        );
    }

    protected function _countPendingTasks(InputInterface $input)
    {
        $result = $this->_swfClient->countPendingActivityTasks(
            array(
                'domain'   => $this->_config['swf']['domain'],
                'taskList' => array(
                    'name' => $this->_taskList
                )
            )
        );

        return $result->get('count');
    }

    /* WEB INTERFACE RELATED METHODS */
    protected function _render(Request $request, ProcessBuilder &$commandBuilder)
    {
//        $task = $request->query->get('task');
        $task = $request->get('task-list');
        if ($task) {
            $commandBuilder->add($task);
        }
    }
} 