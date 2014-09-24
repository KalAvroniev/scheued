<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 3/09/14
 * Time: 10:51 PM
 */
namespace Scheued\Command\Decider;

use Scheued\Command\AbstractDecider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExampleDecider extends AbstractDecider
{
    protected function configure()
    {
        $this->setDescription('This is an example decider');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $output->writeln(json_encode($this->_decision));
    }

    protected function _decide($decisionData)
    {
        $result = array();
        foreach($decisionData as $eventType => $data) {
            switch($eventType) {
                case 'WorkflowExecutionStarted': // Schedule the first activity in our workflow
                    $this->_decision = $this->_scheduleActivityTask(
                        'Example-Test',
                        '0.1',
                        $data['taskList']['name'],
                        $data['input'],
                        json_encode(array('step' => 1))
                    );
                    break 2;
                case 'ActivityTaskScheduled':
                    $control = json_decode($data['control'], true);
                    // We are coming from ActivityTaskCompleted
                    if(!empty($result)) {
                        switch ($control['step']) {
                            case 1:
                                $this->_decision = $this->_scheduleActivityTask(
                                    'Example-Test',
                                    '0.1',
                                    $data['taskList']['name'],
                                    $result['result'],
                                    json_encode(array('step' => 2))
                                );
                                break;
                            default:
                                // Return result and complete workflow
                                $this->_decision = $this->_completeWorkflowExecution($result['result']);
                        }
                    } else { // Retrying a job
                        ++$control['retry'];
                        $control         = json_encode($control);
                        $this->_decision = $this->_scheduleActivityTask(
                            'Example-Test',
                            '0.1',
                            $data['taskList']['name'],
                            isset($data['input']) ? $data['input'] : '',
                            $control
                        );
                    }
                    break 2;
                case 'ActivityTaskCompleted':
                    $result = $data;
                    break;
                case 'ActivityTaskCanceled':
                    if($data == 'No need to run this') {
                        // Return result and complete workflow
                        $this->_decision = $this->_cancelWorkflowExecution($data);
                        break 2;
                    }
                    // Might have to retry
                    break;
                case 'MaxRetriesReached': // We've reached the retry limit for a particular activity
                    $this->_decision = $this->_failWorkflowExecution($data['reason']);
                    break 2;
            }
        }

        $this->_sendDecision();
    }
} 