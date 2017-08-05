<?php

namespace Ryadnov\ZenMoney\Scripts;

use Ramsey\Uuid\Uuid;
use Ryadnov\ZenMoney\Api\V8\RequestDiff;

class ReminderRenewal
{
    protected $token;
    protected $sync_data = [];

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function run()
    {
        $request = new RequestDiff([
            'token' => $this->token,
        ]);

        $data = $request->execute([
            'serverTimestamp' => 0,
            'forceFetch'      => ['reminderMarker', 'reminder'],
        ]);

        $this->processData($data['reminder'], $data['reminderMarker']);

        if (count($this->sync_data)) {
            $request->execute(array_merge(
                $this->sync_data,
                [
                    'serverTimestamp' => time(),
                ]
            ));

            if (array_key_exists('reminderMarker', $this->sync_data)) {
                echo "Added " . count($this->sync_data['reminderMarker']) . " markers \n";
            }
        }
    }

    /**
     * @param array $reminders
     * @param array $markers
     */
    protected function processData($reminders, $markers)
    {
        $date_now = new \DateTime("now");

        /**
         * reminder_id => [
         *   'reminder'    => array (Reminder)
         *   'last_marker' => array (Latest reminder marker)
         * ]
         */
        $data = [];

        foreach ($reminders as $reminder) {
            if (isset($reminder['interval'])) {
                if ($reminder['endDate'] === null || (new \DateTime($reminder['endDate'])) > $date_now) {
                    $data[$reminder['id']]['reminder'] = $reminder;
                }
            }
        }

        foreach ($markers as $marker) {
            $r_id = $marker['reminder'];

            if (array_key_exists($r_id, $data)) {
                if (!array_key_exists('last_marker', $data[$r_id]) || (new \DateTime($marker['date'])) > new \DateTime($data[$r_id]['last_marker']['date'])) {
                    $data[$r_id]['last_marker'] = $marker;
                }
            }
        }

        foreach ($data as $item) {
            $reminder = $item['reminder'];

            if (isset($reminder['endDate'])) {
                $date_end = new \DateTime($reminder['endDate']);
            } else {
                /**
                 * Для цепочек без даты окончания, создаем искуственную дату окончания
                 */
                switch ($reminder['interval']) {
                    case 'year' :
                        $date_interval = new \DateInterval('P5Y1D');
                        break;
                    case 'month' :
                        $date_interval = new \DateInterval('P2Y1D');
                        break;
                    case 'day' :
                    default :
                        $date_interval = new \DateInterval('P18M1D');
                        break;
                }
                $date_end = (new \DateTime('now'))->add($date_interval);
            }

            $this->tryAddNewMarker($item['last_marker'], $date_end, $reminder['interval'], $reminder['step'], $reminder['points']);
        }
    }

    protected function tryAddNewMarker($last_marker, $date_end, $interval, $step, $points)
    {
        // Для года и месяца "точки" не используются
        if ($interval == 'year') {
            $date_interval = new \DateInterval("P{$step}Y");
        } elseif ($interval == 'month') {
            $date_interval = new \DateInterval("P{$step}M");
        } elseif ($interval == 'day') {
            // Самый простой вариант - повторять каждый N день
            if (count($points) == 1 && $points[0] == 0) {
                $date_interval = new \DateInterval("P{$step}D");
            } else {
                // этот вариант в интерфайсе показывается как "повторять еженедельно, по определенным дням"
                $day_n = (new \DateTime($last_marker['date']))->format('w');

                // конвертируем в формат точек
                if ($day_n == 0) {
                    $day_n += 7;
                }
                $day_n -= 1;

                // на всякий случай сбрасываем ключи
                $reset_points = array_values($points);

                $current_key = array_search($day_n, $reset_points);

                if ($current_key + 1 == count($reset_points)) {
                    $days_step = $step - $day_n;
                } else {
                    $days_step = $reset_points[$current_key + 1] - $reset_points[$current_key];
                }

                $date_interval = new \DateInterval("P{$days_step}D");
            }
        }
        $date_next = (new \DateTime($last_marker['date']))->add($date_interval);

        if ($date_end > $date_next) {
            $new_marker            = $last_marker;
            $new_marker['id']      = Uuid::uuid4()->toString();
            $new_marker['changed'] = time();
            $new_marker['date']    = $date_next->format('Y-m-d');

            $this->addToSync('reminderMarker', $new_marker);
            $this->tryAddNewMarker($new_marker, $date_end, $interval, $step, $points);
        }
    }

    protected function addToSync($key, $value)
    {
        if (!array_key_exists($key, $this->sync_data)) {
            $this->sync_data[$key] = [];
        }

        $this->sync_data[$key][] = $value;
    }
}
