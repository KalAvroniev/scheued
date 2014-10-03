<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 29/09/2014
 * Time: 22:33
 */
namespace Scheued\Command;

use Aws\Swf\Enum\EventType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class WatchDog extends AbstractDeciderParser
{
    protected function configure()
    {
        $name = $this->_getClassName(AbstractWorkflow::TYPE);
        parent::configure();
        $this->setDescription('This the watch dog')
            ->setName($name)
            ->setProcessTitle($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        if ($this->_countOpenExecutions($input)) {
            foreach ($this->_listOpenExecutions($input) as $workflow) {
                $resumeData = array('signaled' => false);
                foreach ($this->_getWorkflowHistory($workflow['execution']) as $task) {
                    if ($this->_parseResumeData($task, $resumeData)) {
                        break;
                    }
                }
                if (isset($resumeData['process']) && isset($resumeData['task_list'])
                    && !$this->_checkIfProcessStillRunning($resumeData)
                ) {
                    if(!$resumeData['signaled']) {
                        $this->_signalWorkflowExecution(
                            $workflow['execution']['workflowId'],
                            $workflow['execution']['runId'],
                            'resumeFlow'
                        );
                    }
                    $this->_swfActionCall(
                        'http://development/scheued/public_html/' . str_replace(':', '/', $resumeData['process']),
                        array('task-list' => $resumeData['task_list'], 'async' => true)
                    );
                }
            }
        }
    }

    protected function _getClassName($type)
    {
        return 'watch-dog';
    }

    protected function _countOpenExecutions(InputInterface $input)
    {
        $result = $this->_swfClient->countOpenWorkflowExecutions(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'startTimeFilter' => array(
                    'oldestDate' => date('Y-m-d h:i:s', strtotime($this->_config['swf']['resume_range']))
                )
            )
        );
        $count  = $result->getAll();

        return $count['count'];
    }

    protected function _listOpenExecutions(InputInterface $input)
    {
        $result = $this->_swfClient->getListOpenWorkflowExecutionsIterator(
            array(
                'domain'          => $this->_config['swf']['domain'],
                'startTimeFilter' => array(
                    'oldestDate' => date('Y-m-d h:i:s', strtotime($this->_config['swf']['resume_range']))
                )
            )
        );

        return $result;
    }

    protected function _getWorkflowHistory($execution)
    {
        $result = $this->_swfClient->getGetWorkflowExecutionHistoryIterator(
            array(
                'domain'       => $this->_config['swf']['domain'],
                'execution'    => $execution,
                'reverseOrder' => true
            )
        );

        return $result;
    }

    protected function _parseResumeData($task, &$resumeData)
    {
        $break = false;
        switch ($task['eventType']) {
            case EventType::ACTIVITY_TASK_STARTED:
                $data                  = call_user_func_array(
                    array($this, "_" . lcfirst($task['eventType'])), array($task)
                );
                $resumeData['process'] = $data[$task['eventType']]['identity'];

                break;
            case EventType::DECISION_TASK_SCHEDULED:
                $data                    = call_user_func_array(
                    array($this, "_" . lcfirst($task['eventType'])), array($task)
                );
                $resumeData['task_list'] = $data[$task['eventType']]['taskList']['name'];
                if (!isset($resumeData['process'])) {
                    $resumeData['process'] = 'decider:' . strstr($resumeData['task_list'], '-', true);
                }
                $break = true;
                break;
            case EventType::ACTIVITY_TASK_SCHEDULED:
                $data                    = call_user_func_array(
                    array($this, "_" . lcfirst($task['eventType'])), array($task)
                );
                $resumeData['task_list'] = $data[$task['eventType']]['taskList']['name'];
                if (!isset($resumeData['process'])) {
                    $resumeData['process'] = 'worker:' . strstr($resumeData['task_list'], '-', true);
                }
                $break = true;
                break;
            case EventType::WORKFLOW_EXECUTION_SIGNALED:
                $resumeData['signaled'] = true;
                break;
            case EventType::TIMER_STARTED:
                $break = true;
                break;
        }

        return $break;
    }

    protected function _checkIfProcessStillRunning($resumeData)
    {
        $process = new Process(
            "ps -eo pid,args | grep " . $resumeData['task_list'] . " | grep " . $resumeData['process']
            . " | grep -v grep | awk '{print $1}'"
        );
        $process->run();
        if(is_null($process->getOutput())) {
            return false;
        } else {
            return true;
        }
    }

    protected function _signalWorkflowExecution($workflowId, $runId, $signalName) {
        $this->_swfClient->signalWorkflowExecution(
            array(
                'domain'     => $this->_config['swf']['domain'],
                'workflowId' => $workflowId,
                'runId'      => $runId,
                'signalName' => $signalName
            )
        );
    }

    protected function _countPendingTasks(InputInterface $input)
    {
    }

    protected function _render(Request $request, ProcessBuilder &$commandBuilder)
    {
    }

    protected function _decide($decisionData)
    {
    }
} 