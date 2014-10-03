<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 2/09/14
 * Time: 11:20 PM
 */
namespace Scheued\Command;

use Aws\Swf\Enum\EventType;
use Aws\Swf\Exception\SwfException;

abstract class AbstractDeciderParser extends AbstractCommand
{
    const TYPE = 'decider';
    protected $_tasks     = array();
    protected $_token     = '';
    protected $_decisions = array();

    protected function _parseDecisionData($i = 0, $decisionData = array())
    {
        try {
            if (is_array($this->_tasks)) {
                $total = count($this->_tasks);
                $valid = false;
            } else {
                $total = $this->_tasks->count();
                $valid = $this->_tasks->valid();
            }
            for (; $i < $total || $valid; $i++) {
                if (is_array($this->_tasks)) {
                    $task = $this->_tasks[$i];
                } else {
                    $this->_tasks->next();
                    $task  = $this->_tasks->current();
                    $valid = $this->_tasks->valid();
                }
                switch ($task['eventType']) {
                    case EventType::TIMER_STARTED:
                    case EventType::ACTIVITY_TASK_COMPLETED:
                        $decisionData = array_merge(
                            $decisionData,
                            call_user_func_array(
                                array($this, "_" . lcfirst($task['eventType'])), array($task)
                            )
                        );
                        break;
                    case EventType::ACTIVITY_TASK_SCHEDULED:
                    case EventType::WORKFLOW_EXECUTION_STARTED:
                        $decisionData = array_merge(
                            $decisionData,
                            call_user_func_array(
                                array($this, "_" . lcfirst($task['eventType'])), array($task)
                            )
                        );
                        break 2;
                    default:
                        call_user_func_array(array($this, "_" . lcfirst($task['eventType'])), array($task));
                }
            }
            if ($decisionData) {
                $this->_decide($decisionData);
            }
        } catch (\Exception $e) {
            $eventType = (isset($task) && isset($task['eventType'])) ? $task['eventType'] : '';
            switch ($eventType) {
                case EventType::ACTIVITY_TASK_TIMED_OUT:
                case EventType::DECISION_TASK_TIMED_OUT:
                case EventType::ACTIVITY_TASK_FAILED:
                case EventType::SCHEDULE_ACTIVITY_TASK_FAILED:
                    // retry/bypass errors to get to valuable data
                    $this->_parseDecisionData($i, $decisionData);
                    break;
                case EventType::ACTIVITY_TASK_CANCELED:
                    // Pass the reason over to the decider
                    $decisionData[$eventType] = $e->getMessage();
                    $this->_parseDecisionData($i, $decisionData);
                    break;
            }
        }
    }

    /**
     * The workflow execution was started
     *
     * @param $task
     *
     * @return array array('WorkflowExecutionStarted' => array(
     *          input, executionStartToCloseTimeout, taskStartToCloseTimeout, childPolicy, taskList, workflowType,
     *          tagList, continuedExecutionRunId, parentWorkflowExecution, parentInitiatedEventId
     *      )
     *  )
     */
    protected function _workflowExecutionStarted($task)
    {
        return array($task['eventType'] => $task['workflowExecutionStartedEventAttributes']);
    }

    /**
     * A request to cancel this workflow execution was made
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _workflowExecutionCancelRequested($task)
    {
        if (isset($task['workflowExecutionCancelRequestedEventAttributes']['cause'])) {
            throw new SwfException($task['workflowExecutionCancelRequestedEventAttributes']['cause']);
        } else {
            //@todo do something else
        }
    }

    /**
     * A decision task was scheduled for the workflow execution
     *
     * @param $task
     */
    protected function _decisionTaskScheduled($task)
    {
        return array($task['eventType'] => $task['decisionTaskScheduledEventAttributes']);
    }

    /**
     * The decision task was dispatched to a decider
     *
     * @param $task
     *
     * @return array array('DecisionTaskStarted' => array(
     *          identity, scheduledEventId
     *      )
     *  )
     */
    protected function _decisionTaskStarted($task)
    {
        return array($task['eventType'] => $task['decisionTaskStartedEventAttributes']);
    }

    /**
     * The decider successfully completed a decision task by calling RespondDecisionTaskCompleted
     *
     * @param $task
     *
     * @return array array('DecisionTaskCompleted' => array(
     *          executionContext, scheduledEventId, startedEventId
     *      )
     *  )
     */
    protected function _decisionTaskCompleted($task)
    {
        return array($task['eventType'] => $task['decisionTaskCompletedEventAttributes']);
    }

    /**
     * The decision task timed out
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _decisionTaskTimedOut($task)
    {
        throw new SwfException($task['decisionTaskTimedOutEventAttributes']['timeoutType']);
    }

    /**
     * An activity task was scheduled for execution
     *
     * @param $task
     *
     * @return array array('ActivityTaskScheduled' => array(
     *          activityType, activityId, input, control, scheduleToStartTimeout, scheduleToCloseTimeout,
     *          startToCloseTimeout, taskList, decisionTaskCompletedEventId, heartbeatTimeout
     *      )
     *  )
     */
    protected function _activityTaskScheduled($task)
    {
        $data = $this->_checkRetryLimit($task['activityTaskScheduledEventAttributes']['control']);
        if (!$data) {
            $data = array($task['eventType'] => $task['activityTaskScheduledEventAttributes']);
        }

        return $data;
    }

    /**
     * Failed to process ScheduleActivityTask decision. This happens when the decision is not configured properly,
     * for example the activity type specified is not registered
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _scheduleActivityTaskFailed($task)
    {
        throw new SwfException($task['scheduleActivityTaskFailedEventAttributes']['cause']);
    }

    /**
     * The scheduled activity task was dispatched to a worker
     *
     * @param $task
     *
     * @return array array('ActivityTaskStarted' => array(
     *          identity, scheduledEventId
     *      )
     *  )
     */
    protected function _activityTaskStarted($task)
    {
        return array($task['eventType'] => $task['activityTaskStartedEventAttributes']);
    }

    /**
     * An activity worker successfully completed an activity task by calling RespondActivityTaskCompleted
     *
     * @param $task
     *
     * @return array array('ActivityTaskCompleted' => array(
     *          result, scheduledEventId, startedEventId
     *      )
     *  )
     */
    protected function _activityTaskCompleted($task)
    {
        return array($task['eventType'] => $task['activityTaskCompletedEventAttributes']);
    }

    /**
     * An activity worker failed an activity task by calling RespondActivityTaskFailed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _activityTaskFailed($task)
    {
        throw new SwfException($task['activityTaskFailedEventAttributes']['reason']);
    }

    /**
     * The activity task timed out
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _activityTaskTimedOut($task)
    {
        throw new SwfException($task['activityTaskTimedOutEventAttributes']['timeoutType']);
    }

    /**
     * The activity task was successfully canceled
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _activityTaskCanceled($task)
    {
        throw new SwfException($task['activityTaskCanceledEventAttributes']['details']);
    }

    /**
     * A RequestCancelActivityTask decision was received by the system
     *
     * @param $task
     *
     * @return array array('ActivityTaskCancelRequested' => array(
     *          decisionTaskCompletedEventId, activityId
     *      )
     *  )
     */
    protected function _activityTaskCancelRequested($task)
    {
        return array($task['eventType'] => $task['activityTaskCancelRequestedEventAttributes']);
    }

    /**
     * Failed to process RequestCancelActivityTask decision. This happens when the decision is not configured properly
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _requestCancelActivityTaskFailed($task)
    {
        throw new SwfException($task['requestCancelActivityTaskFailedEventAttributes']['cause']);
    }

    /**
     * An external signal was received for the workflow execution
     *
     * @param $task
     *
     * @return array array('WorkflowExecutionSignaled' => array(
     *          signalName, input, externalWorkflowExecution, externalInitiatedEventId
     *      )
     *  )
     */
    protected function _workflowExecutionSignaled($task)
    {
        return array($task['eventType'] => $task['workflowExecutionSignaledEventAttributes']);
    }

    /**
     * A marker was recorded in the workflow history as the result of a RecordMarker decision
     *
     * @param $task
     *
     * @return array array('MarkerRecorded' => array(
     *          markerName, details, decisionTaskCompletedEventId
     *      )
     *  )
     */
    protected function _markerRecorded($task)
    {
        return array($task['eventType'] => $task['markerRecordedEventAttributes']);
    }

    /**
     * A timer was started for the workflow execution due to a StartTimer decision
     *
     * @param $task
     *
     * @return array array('TimerStarted' => array(
     *          timerId, control, startToFireTimeout, decisionTaskCompletedEventId
     *      )
     *  )
     */
    protected function _timerStarted($task)
    {
        $data = $this->_checkRetryLimit($task['timerStartedEventAttributes']['control']);
        if (!$data) {
            $data = array($task['eventType'] => $task['timerStartedEventAttributes']);
        }

        return $data;
    }

    /**
     * Failed to process StartTimer decision. This happens when the decision is not configured properly,
     * for example a timer already exists with the specified timer Id
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _startTimerFailed($task)
    {
        throw new SwfException($task['startTimerFailedEventAttributes']['cause']);
    }

    /**
     * A timer, previously started for this workflow execution, fired
     *
     * @param $task
     *
     * @return array array('TimerFired' => array(
     *          timerId, startedEventId
     *      )
     *  )
     */
    protected function _timerFired($task)
    {
        return array($task['eventType'] => $task['timerFiredEventAttributes']);
    }

    /**
     * A timer, previously started for this workflow execution, was successfully canceled
     *
     * @param $task
     *
     * @return array array('TimerCanceled' => array(
     *          timerId, startedEventId, decisionTaskCompletedEventId
     *      )
     *  )
     */
    protected function _timerCanceled($task)
    {
        return array($task['eventType'] => $task['timerCanceledEventAttributes']);
    }

    /**
     * Failed to process CancelTimer decision. This happens when the decision is not configured properly,
     * for example no timer exists with the specified timer Id
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _cancelTimerFailed($task)
    {
        throw new SwfException($task['cancelTimerFailedEventAttributes']['cause']);
    }

    /**
     * A request was made to start a child workflow execution
     *
     * @param $task
     *
     * @return array array('StartChildWorkflowExecutionInitiated' => array(
     *          workflowId, workflowType, control, input, executionStartToCloseTimeout, taskList,
     *          decisionTaskCompletedEventId, childPolicy, taskStartToCloseTimeout, tagList
     *      )
     *  )
     */
    protected function _startChildWorkflowExecutionInitiated($task)
    {
        $data = $this->_checkRetryLimit($task['startChildWorkflowExecutionInitiatedEventAttributes']['control']);
        if (!$data) {
            $data = array($task['eventType'] => $task['startChildWorkflowExecutionInitiatedEventAttributes']);
        }

        return $data;
    }

    /**
     * Failed to process StartChildWorkflowExecution decision. This happens when the decision is not configured properly,
     * for example the workflow type specified is not registered
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _startChildWorkflowExecutionFailed($task)
    {
        //@todo decide if we need to return any other data
        throw new SwfException($task['startChildWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * A child workflow execution was successfully started
     *
     * @param $task
     *
     * @return array array('ChildWorkflowExecutionStarted' => array(
     *          workflowExecution, workflowType, initiatedEventId
     *      )
     *  )
     */
    protected function _childWorkflowExecutionStarted($task)
    {
        return array($task['eventType'] => $task['childWorkflowExecutionStartedEventAttributes']);
    }

    /**
     * A child workflow execution, started by this workflow execution, completed successfully and was closed
     *
     * @param $task
     *
     * @return array array('ChildWorkflowExecutionCompleted' => array(
     *          workflowExecution, workflowType, result, initiatedEventId, startedEventId
     *      )
     *  )
     */
    protected function _childWorkflowExecutionCompleted($task)
    {
        return array($task['eventType'] => $task['childWorkflowExecutionCompletedEventAttributes']);
    }

    /**
     * A child workflow execution, started by this workflow execution, failed to complete successfully and was closed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _childWorkflowExecutionFailed($task)
    {
        //@todo need to decide if we are going to get any of the other values
        throw new SwfException($task['childWorkflowExecutionFailedEventAttributes']['reason']);
    }

    /**
     * A child workflow execution, started by this workflow execution, timed out and was closed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _childWorkflowExecutionTimedOut($task)
    {
        //@todo need to decide if we are going to get any of the other values
        throw new SwfException($task['childWorkflowExecutionTimedOutEventAttributes']['timeoutType']);
    }

    /**
     * A child workflow execution, started by this workflow execution, was canceled and closed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _childWorkflowExecutionCanceled($task)
    {
        //@todo need to decide if we are going to get any of the other values
        throw new SwfException($task['childWorkflowExecutionCanceledEventAttributes']['details']);
    }

    /**
     * A child workflow execution, started by this workflow execution, was terminated
     *
     * @param $task
     *
     * @return array array('ChildWorkflowExecutionTerminated' => array(
     *          workflowExecution, workflowType, initiatedEventId, startedEventId
     *      )
     *  )
     */
    protected function _childWorkflowExecutionTerminated($task)
    {
        return array($task['eventType'] => $task['childWorkflowExecutionTerminatedEventAttributes']);
    }

    /**
     * A request to signal an external workflow was made
     *
     * @param $task
     *
     * @return array array('SignalExternalWorkflowExecutionInitiated' => array(
     *          workflowId, runId, signalName, input, decisionTaskCompletedEventId, control
     *      )
     *  )
     */
    protected function _signalExternalWorkflowExecutionInitiated($task)
    {
        $data = $this->_checkRetryLimit($task['signalExternalWorkflowExecutionInitiatedEventAttributes']['control']);
        if (!$data) {
            $data = array($task['eventType'] => $task['signalExternalWorkflowExecutionInitiatedEventAttributes']);
        }

        return $data;
    }

    /**
     * A signal, requested by this workflow execution, was successfully delivered to the target external workflow execution
     *
     * @param $task
     *
     * @return array array('ExternalWorkflowExecutionSignaled' => array(
     *          workflowExecution, initiatedEventId
     *      )
     *  )
     */
    protected function _externalWorkflowExecutionSignaled($task)
    {
        return array($task['eventType'] => $task['externalWorkflowExecutionSignaledEventAttributes']);
    }

    /**
     * The request to signal an external workflow execution failed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _signalExternalWorkflowExecutionFailed($task)
    {
        throw new SwfException($task['signalExternalWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * A request was made to request the cancellation of an external workflow execution
     *
     * @param $task
     *
     * @return array array('RequestCancelExternalWorkflowExecutionInitiated' => array(
     *          workflowId, runId, decisionTaskCompletedEventId, control
     *      )
     *  )
     */
    protected function _requestCancelExternalWorkflowExecutionInitiated($task)
    {
        $data = $this->_checkRetryLimit(
            $task['requestCancelExternalWorkflowExecutionInitiatedEventAttributes']['control']
        );
        if (!$data) {
            $data = array($task['eventType'] => $task['requestCancelExternalWorkflowExecutionInitiatedEventAttributes']);
        }

        return $data;
    }

    /**
     * Request to cancel an external workflow execution was successfully delivered to the target execution
     *
     * @param $task
     *
     * @return array array ('ExternalWorkflowExecutionCancelRequested' => array(
     *          workflowExecution, initiatedEventId
     *      )
     *  )
     */
    protected function _externalWorkflowExecutionCancelRequested($task)
    {
        return array($task['eventType'] => $task['externalWorkflowExecutionCancelRequestedEventAttributes']);
    }

    /**
     * Request to cancel an external workflow execution failed
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _requestCancelExternalWorkflowExecutionFailed($task)
    {
        throw new SwfException($task['requestCancelExternalWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * This could happen if there is an unhandled decision task pending in the workflow execution
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _completeWorkflowExecutionFailed($task)
    {
        throw new SwfException($task['completeWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * This could happen if there is an unhandled decision task pending in the workflow execution.
     * The preceding error events might occur due to an error in the decider logic,
     * which might put the workflow execution in an unstable state.
     * The cause field in the event structure for the error event indicates the cause of the error
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _failWorkflowExecutionFailed($task)
    {
        throw new SwfException($task['failWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * This could happen if there is an unhandled decision task pending in the workflow execution
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _cancelWorkflowExecutionFailed($task)
    {
        throw new SwfException($task['cancelWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * This could happen if there is an unhandled decision task pending in the workflow execution
     * or the ContinueAsNewWorkflowExecution decision was not configured correctly
     *
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _continueAsNewWorkflowExecutionFailed($task)
    {
        throw new SwfException($task['continueAsNewWorkflowExecutionFailedEventAttributes']['cause']);
    }

    /**
     * @param $task
     *
     * @throws \Aws\Swf\Exception\SwfException
     * @todo with error handling
     */
    protected function _decisionTaskFailed($task)
    {
        throw new SwfException($task['recordMarkerFailedEventAttributes']['cause']);
    }

    /**
     * See if we are passed the retry limit
     *
     * @param $control
     *
     * @return array|null
     */
    protected function _checkRetryLimit($control)
    {
        $control = json_decode($control, true);
        if ($this->_config['swf']['retry'] < $control['retry']) {
            return array(
                'MaxRetriesReached' => array(
                    'reason' => 'We have reached the retry limit'
                )
            );
        }

        return null;
    }


    /**
     * Decide based on workflow data
     *
     * @param $decisionData
     */
    abstract protected function _decide($decisionData);
} 