<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 2/09/14
 * Time: 11:20 PM
 */
namespace Scheued\Command;

use Aws\Swf\Enum\DecisionType;
use ReflectionClass;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\ProcessBuilder;

abstract class AbstractDecider extends AbstractDeciderParser
{
    const TYPE = 'decider';
    protected $_tasks = array();

    /**
     * This is where we configure the command name, description and arguments or options
     * Default arguments:
     * task
     */
    protected function configure()
    {
        parent::configure();
        $name = $this->_getClassName(AbstractDecider::TYPE);
        $this->setName($name)
            ->setProcessTitle($name)
            ->addArgument(
                'task-list',
                InputArgument::OPTIONAL, 'Used to specify the task list if we are not using the default',
                $this->_getTaskName()
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_taskList = $input->getArgument('task-list');
        parent::execute($input, $output);
        if ($this->_countPendingTasks($input)) {
            $this->_poll($input);
        }
    }

    protected function _countPendingTasks(InputInterface $input)
    {
        $result = $this->_swfClient->countPendingDecisionTasks(
            array(
                'domain'   => $this->_config['swf']['domain'],
                'taskList' => array(
                    'name' => $this->_taskList
                )
            )
        );

        return $result->get('count');
    }

    protected function _poll(InputInterface $input)
    {
        // Use this to retrieve taskToken
        $workflowInfo = $this->_swfClient->pollForDecisionTask(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'taskList'        => array(
                    'name' => $this->_taskList,
                ),
                'identity'        => $this->getName(),
                'reverseOrder'    => true,
                'maximumPageSize' => 1
            )
        );
        $this->_token = $workflowInfo['taskToken'];
        $this->_tasks = $workflowInfo['events'];
        $this->_parseDecisionData();
        $this->_tasks = $this->_swfClient->getPollForDecisionTaskIterator(
            array(
                'domain'        => $this->_config['swf']['domain'],
                'taskList'      => array(
                    'name' => $this->_taskList,
                ),
                'identity'      => $this->getName(),
                'reverseOrder'  => true,
                'nextPageToken' => $workflowInfo['nextPageToken']
            )
        );
        $this->_parseDecisionData();
    }

    protected function _sendDecision()
    {
        if (!empty($this->_decisions)) {
            $this->_swfClient->respondDecisionTaskCompleted(
                array(
                    'taskToken' => $this->_token,
                    'decisions' => $this->_decisions
                )
            );
            // call a worker
            foreach($this->_decisions as $decision) {
                switch ($decision['decisionType']) {
                    case DecisionType::SCHEDULE_ACTIVITY_TASK:
                        $worker = strtolower(
                            $decision['scheduleActivityTaskDecisionAttributes']['activityType']['name']
                        );
                        $this->_swfActionCall(
                            'http://development/scheued/public_html/worker/' . $worker,
                            array('task-list' => $this->_taskList, 'async' => true)
                        );
                        break;
                }
            }
        }
    }

    /**
     * Cancels a previously started timer and records a TimerCanceled event in the history
     *
     * @param $timerId
     *
     * @return array
     */
    protected function _cancelTimer($timerId)
    {
        $data = array('timerId' => $timerId);

        return array('decisionType' => DecisionType::CANCEL_TIMER, 'cancelTimerDecisionAttributes' => $data);
    }

    /**
     * Closes the workflow execution and records a WorkflowExecutionCanceled event in the history
     *
     * @param string $details
     *
     * @return array
     */
    protected function _cancelWorkflowExecution($details)
    {
        $data = array('details' => $details);

        return array('decisionType'                              => DecisionType::CANCEL_WORKFLOW_EXECUTION,
                     'cancelWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Closes the workflow execution and records a WorkflowExecutionCompleted event in the history
     *
     * @param mixed $result
     *
     * @return array
     */
    protected function _completeWorkflowExecution($result)
    {
        $data = array('result' => $result);

        return array(
            'decisionType'                                => DecisionType::COMPLETE_WORKFLOW_EXECUTION,
            'completeWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Closes the workflow execution and starts a new workflow execution of the same type using the same workflow id
     * and a unique run Id. A WorkflowExecutionContinuedAsNew event is recorded in the history
     *
     * @param        $taskList
     * @param string $input
     * @param string $executionStartToCloseTimeout
     * @param string $taskStartToCloseTimeout
     * @param string $childPolicy
     * @param string $tagList
     * @param string $workflowTypeVersion
     *
     * @return array
     */
    protected function _continueAsNewWorkflowExecution(
        $taskList,
        $input = '',
        $executionStartToCloseTimeout = 'NONE',
        $taskStartToCloseTimeout = 'NONE',
        $childPolicy = 'NONE',
        $tagList = '',
        $workflowTypeVersion = ''
    ) {
        $data = array('taskList' => array('name' => $taskList));
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array(
            'decisionType'                                     => DecisionType::CONTINUE_AS_NEW_WORKFLOW_EXECUTION,
            'continueAsNewWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Closes the workflow execution and records a WorkflowExecutionFailed event in the history
     *
     * @param string $reason
     * @param string $details
     *
     * @return array
     */
    protected function _failWorkflowExecution($reason, $details = '')
    {
        $data = array('reason' => $reason);
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array('decisionType'                            => DecisionType::FAIL_WORKFLOW_EXECUTION,
                     'failWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Records a MarkerRecorded event in the history. Markers can be used for adding custom information in the history
     * for instance to let deciders know that they do not need to look at the history beyond the marker event
     *
     * @param        $name
     * @param string $details
     *
     * @return array
     */
    protected function _recordMarker($name, $details = '')
    {
        $data = array('makerName' => $name);
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array('decisionType' => DecisionType::RECORD_MARKER, 'recordMarkerDecisionAttributes' => $data);
    }

    /**
     * Attempts to cancel a previously scheduled activity task. If the activity task was scheduled but
     * has not been assigned to a worker, then it will be canceled. If the activity task was already assigned to a worker,
     * then the worker will be informed that cancellation has been requested in the response to RecordActivityTaskHeartbeat
     *
     * @param $activityId
     *
     * @return array
     */
    protected function _requestCancelActivityTask($activityId)
    {
        $data = array('activityId' => $activityId);

        return array(
            'decisionType'                                => DecisionType::REQUEST_CANCEL_ACTIVITY_TASK,
            'requestCancelActivityTaskDecisionAttributes' => $data
        );
    }

    /**
     * Requests that a request be made to cancel the specified external workflow execution and
     * records a RequestCancelExternalWorkflowExecutionInitiated event in the history
     *
     * @param        $workflowId
     * @param string $runId
     * @param string $control
     *
     * @return array
     */
    protected function _requestCancelExternalWorkflowExecution($workflowId, $runId = '', $control = '')
    {
        $data = array('workflowId' => $workflowId);
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array(
            'decisionType'                                             => DecisionType::REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION,
            'requestCancelExternalWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Schedules an activity task
     *
     * @param string $name
     * @param string $version
     * @param string $taskList
     * @param string $control
     * @param string $input
     * @param string $scheduleToCloseTimeout
     * @param string $scheduleToStartTimeout
     * @param string $startToCloseTimeout
     * @param string $heartbeatTimeout
     *
     * @return array
     */
    protected function _scheduleActivityTask(
        $name,
        $version,
        $taskList,
        $input = '',
        $control = '',
        $scheduleToCloseTimeout = 'NONE',
        $scheduleToStartTimeout = 'NONE',
        $startToCloseTimeout = 'NONE',
        $heartbeatTimeout = 'NONE'
    ) {
        $data = array(
            'activityType'           => array(
                'name'    => $name,
                'version' => $version
            ),
            'activityId'             => $this->_generateId(),
            'taskList'               => array(
                'name' => $taskList
            )
        );
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array('decisionType'                           => DecisionType::SCHEDULE_ACTIVITY_TASK,
                     'scheduleActivityTaskDecisionAttributes' => $data
        );
    }

    /**
     * Requests a signal to be delivered to the specified external workflow execution and
     * records a SignalExternalWorkflowExecutionInitiated event in the history
     *
     * @param        $workflowId
     * @param        $signalName
     * @param string $runId
     * @param string $input
     * @param string $control
     *
     * @return array
     */
    protected function _signalExternalWorkflowExecution(
        $workflowId,
        $signalName,
        $runId = '',
        $input = '',
        $control = ''
    ) {
        $data = array(
            'workflowId' => $workflowId,
            'signalName' => $signalName
        );
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array(
            'decisionType'                                      => DecisionType::SIGNAL_EXTERNAL_WORKFLOW_EXECUTION,
            'signalExternalWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Requests that a child workflow execution be started and records a StartChildWorkflowExecutionInitiated event in the history.
     * The child workflow execution is a separate workflow execution with its own history
     *
     * @param        $name
     * @param        $version
     * @param        $workflowId
     * @param        $taskList
     * @param string $control
     * @param string $input
     * @param string $executionStartToCloseTimeout
     * @param string $taskStartToCloseTimeout
     * @param string $childPolicy
     * @param string $tagList
     *
     * @return array
     */
    protected function _startChildWorkflowExecution(
        $name,
        $version,
        $workflowId,
        $taskList,
        $input = '',
        $control = '',
        $executionStartToCloseTimeout = 'NONE',
        $taskStartToCloseTimeout = 'NONE',
        $childPolicy = '',
        $tagList = ''
    ) {
        $data = array(
            'workflowType' => array(
                'name'    => $name,
                'version' => $version
            ),
            'workflowId'   => $workflowId,
            'taskList'     => array(
                'name' => $taskList
            )
        );
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array(
            'decisionType'                                  => DecisionType::START_CHILD_WORKFLOW_EXECUTION,
            'startChildWorkflowExecutionDecisionAttributes' => $data
        );
    }

    /**
     * Starts a timer for this workflow execution and records a TimerStarted event in the history.
     * This timer will fire after the specified delay and record a TimerFired event
     *
     * @param        $timerId
     * @param        $startToFireTimeout
     * @param string $control
     *
     * @return array
     */
    protected function _startTimer($timerId, $startToFireTimeout, $control = '')
    {
        $data = array(
            'timerId'            => $timerId,
            'startToFireTimeout' => $startToFireTimeout
        );
        $this->_buildOptionalData($data, __FUNCTION__, func_get_args());

        return array('decisionType' => DecisionType::START_TIMER, 'startTimerDecisionAttributes' => $data);
    }

    protected function _buildOptionalData(&$data, $function, $args)
    {
        $reflector  = new ReflectionClass(__CLASS__);
        $parameters = $reflector->getMethod($function)->getParameters();
        foreach ($parameters as $parameter) {
            if ($parameter->isDefaultValueAvailable() &&
                (!empty($args[$parameter->getPosition()]) || $parameter->getDefaultValue() === 'NONE')
            ) {
                $name = $parameter->getName();
                switch ($name) {
                    case 'control':
                        $value       = isset($args[$parameter->getPosition()]) ?
                            json_decode($args[$parameter->getPosition()], true) : array();
                        $value       = array_merge(array('retry' => 1), $value);
                        $data[$name] = json_encode($value);
                        break;
                    default:
                        $data[$name] = isset($args[$parameter->getPosition()]) ?
                            $args[$parameter->getPosition()] : $parameter->getDefaultValue();
                }
            }
        }
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