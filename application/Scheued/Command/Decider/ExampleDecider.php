<?php
/**
 * Created by PhpStorm.
 * User: kal
 * Date: 3/09/14
 * Time: 10:51 PM
 */
namespace Scheued\Command\Decider;

use Aws\Swf\Enum\EventType;
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
        $output->writeln(json_encode($this->_decisions));
    }

    protected function _decide($decisionData)
    {
        $result  = array();
        $control = array();

        foreach ($decisionData as $eventType => $data) {
            switch ($eventType) {
                case EventType::WORKFLOW_EXECUTION_STARTED: // Schedule the first activity in our workflow
                    $this->_decisions[] = $this->_scheduleActivityTask(
                        'Example-Test',
                        '0.1',
                        $data['taskList']['name'],
                        $data['input'],
                        json_encode(array('step' => 1)),
                        'NONE',
                        $this->_config['swf']['decision_timeout']
                    );
                    break 2;
                case EventType::ACTIVITY_TASK_SCHEDULED:
                    if(empty($control)) {
                        $control = json_decode($data['control'], true);
                    }
                    // We are coming from ActivityTaskCompleted
                    if (!empty($result)) {
                        switch ($control['step']) {
                            case 1:
                                // Delay next step
                                $this->_decisions[] = $this->_scheduleNextStep(
                                    date('Y-m-d h:i:s', strtotime('+1 minute')),
                                    json_encode(array('step' => 2))
                                );
                                break;
                            case 2:
                                $this->_decisions[] = $this->_scheduleActivityTask(
                                    'Example-Test',
                                    '0.1',
                                    $data['taskList']['name'],
                                    $result['result'],
                                    json_encode(array('step' => 3)),
                                    'NONE',
                                    $this->_config['swf']['decision_timeout']
                                );
                                break;
                            default:
                                // Return result and complete workflow
                                $this->_decisions[] = $this->_completeWorkflowExecution($result['result']);
                        }
                    } else { // Retrying a job
                        ++$control['retry'];
                        $this->_decisions[] = $this->_scheduleActivityTask(
                            'Example-Test',
                            '0.1',
                            $data['taskList']['name'],
                            isset($data['input']) ? $data['input'] : '',
                            json_encode($control),
                            'NONE',
                            $this->_config['swf']['decision_timeout']
                        );
                    }
                    break 2;
                case EventType::ACTIVITY_TASK_COMPLETED:
                    $result = $data;
                    break;
                case EventType::ACTIVITY_TASK_CANCELED:
                    if ($data == 'No need to run this') {
                        // Return result and complete workflow
                        $this->_decisions[] = $this->_cancelWorkflowExecution($data);
                        break 2;
                    }
                    // Might have to retry
                    break;
                case EventType::TIMER_STARTED:
                    if (empty($control)) {
                        $control = json_decode($data['control'], true);
                    }
                    break;
                case 'MaxRetriesReached': // We've reached the retry limit for a particular activity
                    $this->_decisions[] = $this->_failWorkflowExecution($data['reason']);
                    break 2;
            }
        }
        $this->_sendDecision();
    }
} 