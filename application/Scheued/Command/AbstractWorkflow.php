<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 14/09/14
 * Time: 12:26 PM
 */
namespace Scheued\Command;

use Aws\Swf\Exception\SwfException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\ProcessBuilder;

class AbstractWorkflow extends AbstractCommand
{
    const TYPE = 'flow';
    protected $_validActions = array(
        'count-closed-exec',
        'list-closed-exec',
        'count-open-exec',
        'list-open-exec',
        'deprecate',
        'describe-type',
        'list-types',
        'start'
    );

    /**
     * This is where we configure the command name, description and arguments or options
     * Default arguments:
     * action - used to tell what action to trigger
     * Default options:
     * ver    - used for version of work flow
     * input  - used to pass any custom data
     * filter - used to pass extra filters when retrieving data from the api
     */
    protected function configure()
    {
        parent::configure();
        $name = $this->_getClassName(AbstractWorkflow::TYPE);
        $this->setName($name)
            ->setProcessTitle($name)
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'The action to be executed. Choose from ("' . implode('", "', $this->_validActions) . '")'
            )
            ->addOption(
                'ver', null, InputOption::VALUE_OPTIONAL, 'Pass this if you want to work with a specific version', ''
            )
            ->addOption(
                'input', null, InputOption::VALUE_OPTIONAL, 'Use this if you need to pass any custom values', ''
            )
            ->addOption(
                'filter', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Used for custom filters',
                array()
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        switch ($input->getArgument('action')) {
            case 'count-closed-exec':
                $result = $this->_countClosedExecutions($input);
                break;
            case 'list-closed-exec':
                $result = $this->_listClosedExecutions($input);
                break;
            case 'count-open-exec':
                $result = $this->_countOpenExecutions($input);
                break;
            case 'list-open-exec':
                $result = $this->_listOpenExecutions($input);
                break;
            case 'deprecate':
                $result = $this->_deprecate($input);
                break;
            case 'describe-type':
                $result = $this->_describeType($input);
                break;
            case 'list-types':
                $result = $this->_listTypes($input);
                break;
            case 'start':
                $result = $this->_start($input);
                break;
            default:
                throw new InvalidArgumentException(
                    'Invalid action passed. You can only use (' .
                    implode('", "', $this->_validActions) . ')'
                );
        }
        $output->writeln(json_encode($result));
    }

    protected function _countClosedExecutions(InputInterface $input)
    {
        $result = $this->_swfClient->countClosedWorkflowExecutions(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'typeFilter'      => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                ),
                'closeTimeFilter' => array(
                    'oldestDate' => date('Y-m-d 00:00:00')
                )
            )
        );

        return $result->getAll();
    }

    protected function _listClosedExecutions(InputInterface $input)
    {
        $results = $this->_swfClient->getListClosedWorkflowExecutionsIterator(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'typeFilter'      => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                ),
                'closeTimeFilter' => array(
                    'oldestDate' => date('Y-m-d 00:00:00')
                )
            )
        );

        return $results->toArray();
    }

    protected function _countOpenExecutions(InputInterface $input)
    {
        $result = $this->_swfClient->countOpenWorkflowExecutions(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'typeFilter'      => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                ),
                'startTimeFilter' => array(
                    'oldestDate' => date('Y-m-d 00:00:00')
                )
            )
        );

        return $result->getAll();
    }

    protected function _listOpenExecutions(InputInterface $input)
    {
        $result = $this->_swfClient->getListOpenWorkflowExecutionsIterator(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'typeFilter'      => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                ),
                'startTimeFilter' => array(
                    'oldestDate' => date('Y-m-d 00:00:00')
                )
            )
        );

        return $result->toArray();
    }

    protected function _deprecate(InputInterface $input)
    {
        $result = $this->_swfClient->deprecateWorkflowType(
            array(
                'domain'       => $this->_config['swf']['domain'],
                'workflowType' => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                )
            )
        );

        return $result->getAll();
    }

    protected function _describeType(InputInterface $input)
    {
        $result = $this->_swfClient->describeWorkflowType(
            array(
                'domain'       => $this->_config['swf']['domain'],
                'workflowType' => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                )
            )
        );

        return $result->getAll();
    }

    protected function _listTypes(InputInterface $input)
    {
        $result = $this->_swfClient->getListWorkflowTypesIterator(
            array(
                'domain'             => $this->_config['swf']['domain'],
                'name'               => $this->_getWorkFlowName(),
                'registrationStatus' => 'REGISTERED'
            )
        );

        return $result->toArray();
    }

    protected function _start(InputInterface $input)
    {
        $id       = $this->_generateId();
        $taskList = $this->_getTaskName() . '-' . $id;
        $result   = $this->_swfClient->startWorkflowExecution(
            array(
                'domain'       => $this->_config['swf']['domain'],
                'workflowId'   => $id,
                'workflowType' => array(
                    'name'    => $this->_getWorkFlowName(),
                    'version' => $input->getOption('ver')
                ),
                'taskList'     => array(
                    'name' => $taskList
                ),
                'input'        => $input->getOption('input')
            )
        );
        // call a decider
        $this->_swfActionCall(
            'http://development/scheued/public_html/decider/' . $this->_getTaskName(),
            array('task' => $taskList, 'async' => true)
        );
        return $result->getAll();
    }

    /**
     * Capitalises the task name to be used as work flow name
     *
     * @return mixed
     */
    protected function _getWorkFlowName()
    {
        return ucwords($this->_getTaskName());
    }

    protected function _countPendingTasks(InputInterface $input)
    {
    }


    /**
     * The workflow execution was closed due to successful completion
     *
     * @param $task
     *
     * @return array array('WorkflowExecutionCompleted' => array(
     *          result, decisionTaskCompletedEventId
     *      )
     *  )
     * COULD BE USED FOR REPORTING SUCCESS LATER ON
     */
    protected function _workflowExecutionCompleted($task)
    {
        return array($task['eventType'] => $task['workflowExecutionCompletedEventAttributes']);
    }

    /**
     * The workflow execution closed due to a failure
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _workflowExecutionFailed($task)
    {
        throw new SwfException($task['workflowExecutionFailedEventAttributes']['reason']);
    }

    /**
     * The workflow execution was closed because a time out was exceeded
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _workflowExecutionTimedOut($task)
    {
        throw new SwfException($task['workflowExecutionTimedOutEventAttributes']['timeoutType']);
    }

    /**
     * The workflow execution was successfully canceled and closed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _workflowExecutionCanceled($task)
    {
        throw new SwfException($task['workflowExecutionCanceledEventAttributes']['details']);
    }

    /**
     * The workflow execution was terminated
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _workflowExecutionTerminated($task)
    {
        //@todo need to figure out when to use cause
        throw new SwfException($task['workflowExecutionTerminatedEventAttributes']['reason']);
    }

    /**
     * The workflow execution was closed and a new execution of the same type was created with the same workflowId
     *
     * @param $task
     *
     * @return array array('WorkflowExecutionContinuedAsNew' => array(
     *          input, decisionTaskCompletedEventId, newExecutionRunId, executionStartToCloseTimeout, taskList,
     *          taskStartToCloseTimeout, childPolicy, tagList, workflowType
     *      )
     *  )
     */
    protected function _workflowExecutionContinuedAsNew($task)
    {
        return array($task['eventType'] => $task['workflowExecutionContinuedAsNewEventAttributes']);
    }

    /* WEB INTERFACE RELATED METHODS */
    protected function _render(Request $request, ProcessBuilder &$commandBuilder)
    {
        $action  = $request->query->get('action');
        $version = $request->query->get('version');
        $input   = $request->query->get('input');
        $filters = $request->query->get('filter');
        $commandBuilder->add($action);
        if ($version) {
            $commandBuilder->add('--ver=' . $version);
        }
        if ($input) {
            $commandBuilder->add('--input=' . $input);
        }
        if ($filters) {
            if (!is_array($filters)) {
                $filters = array($filters);
            }
            foreach ($filters as $filter) {
                $commandBuilder->add('--filter=' . $filter);
            }
        }
    }
}