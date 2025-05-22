<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Reporting;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Model\DailyStatistic;
use App\Model\DateStatisticInterface;
use App\Model\Statistic\StatisticDate;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Timesheet\TimesheetStatisticService;
use DateTimeInterface;
use App\Entity\Team;
use App\Entity\Project;

abstract class AbstractUserReportController extends AbstractController
{
    public function __construct(protected TimesheetStatisticService $statisticService, private ProjectRepository $projectRepository, private ActivityRepository $activityRepository)
    {
    }

    protected function canSelectUser(): bool
    {
        // also found in App\EventSubscriber\Actions\UserSubscriber
        if (!$this->isGranted('view_other_timesheet') || !$this->isGranted('view_other_reporting')) {
            return false;
        }

        return true;
    }

    protected function getStatisticDataRaw(DateTimeInterface $begin, DateTimeInterface $end, User $user): array
    {
        return $this->statisticService->getDailyStatisticsGrouped($begin, $end, [$user]);
    }

    protected function createStatisticModel(DateTimeInterface $begin, DateTimeInterface $end, User $user): DateStatisticInterface
    {
        return new DailyStatistic($begin, $end, $user);
    }

    protected function prepareReport(DateTimeInterface $begin, DateTimeInterface $end, User $user, bool $crFilter): array
    {
        
        $startDate = $begin->format('Y-m-d H:i:s.u');  // Convert to string
        $endDate = $end->format('Y-m-d H:i:s.u');

        $projectData = $this->projectRepository->getDailyProjectData($user->getId(), $startDate, $endDate, $crFilter);

        $transformedData = [];

        foreach ($projectData as $entry) {
            $workdate = $entry['workdate'];  // This will match the alias you used in the query: 'DATE(t.start_time) AS date'
            $weekday = $entry['weekday'];
            $projectName = $entry['project_name'];
            $secondsWorked = $entry['total_duration'];   // This is in seconds
            $jiraIds = $entry['jira_ids'];  
            $description = $entry['description']; 
            $component = $entry['component'];
            $username = $entry['username'];

            // Convert seconds to hours
            $hoursWorked = floatval(sprintf('%d.%02d', floor($secondsWorked / 3600), floor(($secondsWorked % 3600) / 60)));

            // Transform grouped data to the final format
            $transformedData[] = [
                'name' => $username,
                'workdate' => (new \DateTime($workdate))->format('j-M-Y'), // Format the date to '1-Jan-2025'
                'weekday' => $weekday,
                'hours' => $hoursWorked,
                'project' => $projectName,
                'jira_ids' => $jiraIds,
                'description' => $description,
                'component' => $component,
            ];

        }

        return $transformedData;
    }

    //Sheet 1
    protected function prepareAllUsersReport(array $userIds, string $startDate, string $endDate, ?Project $project = null, ?Int $team = null, bool $crFilter): array
    {

        $projectData = $this->projectRepository->getAllUsersProjectData($userIds, $startDate, $endDate, $project, $team, $crFilter);

        $reportData = [];
        
        // Collect all dates for the reporting period
        $dates = [];
        $current = new \DateTime($startDate);
        $endDt = new \DateTime($endDate);
        while ($current <= $endDt) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }

        // Step 1: Group data by user + Team
        foreach ($projectData as $entry) {
            $username = $entry['username'];
            $role = $entry['role'] ?? 'N/A';
            $workdate = $entry['workdate'];
            $userId   = $entry['user_id'] ?? null;
            
            // Format durations as float hours with decimals
            $onsiteDuration  = floatval(sprintf('%d.%02d', floor($entry['onsite_duration'] / 3600), floor(($entry['onsite_duration'] % 3600) / 60)));
            $offsiteDuration = floatval(sprintf('%d.%02d', floor($entry['offsite_duration'] / 3600), floor(($entry['offsite_duration'] % 3600) / 60)));
            $totalDuration   = floatval(sprintf('%d.%02d', floor($entry['total_duration'] / 3600), floor(($entry['total_duration'] % 3600) / 60)));
            
            $activityName = strtolower(trim($entry['activity_name'] ?? ''));

            // Prefer team name from DB, fallback to user lookup
            $team = $entry['team_name'] ?? null;
            if ($team === null && $userId !== null) {
                $teams = $this->projectRepository->getTeamsForUser($userId, $project ? $project->getId() : null);
                $teamNames = array_map(fn($t) => $t['name'], $teams);
                $team = !empty($teamNames) ? implode(', ', $teamNames) : 'N/A';
            }
            $teamKey = $team ?: 'N/A';

            $reportKey = $username . '|' . $teamKey;

            if (!isset($reportData[$reportKey])) {
                 // Initialize daily arrays
                $reportData[$reportKey] = [
                    'name'       => $username,
                    'role'       => $role,
                    'team'       => $teamKey,
                    'total_work' => 0,
                    'onsite'     => 0,
                    'offsite'    => 0,
                    'daily'      => array_fill_keys($dates, 0),
                    'activities' => array_fill_keys($dates, []),
                    'labels'     => array_fill_keys($dates, []), 
                ];
            }
            // Accumulate totals
            $reportData[$reportKey]['total_work'] += $entry['total_duration'];
            $reportData[$reportKey]['onsite'] += $entry['onsite_duration'];
            $reportData[$reportKey]['offsite'] += $entry['offsite_duration'];
            $reportData[$reportKey]['daily'][$workdate] += $totalDuration;
            $reportData[$reportKey]['activities'][$workdate][] = $activityName;

            if ((int)$entry['isLabled'] === 1 && !empty($entry['label_symbol'])) {
                $reportData[$reportKey]['labels'][$workdate][] = $entry['label_symbol'];
            }
        }

        // Converting Min_totals in hours & Min
        foreach ($reportData as $reportKey => $data){
            $reportData[$reportKey]['total_work'] = floatval(sprintf('%d.%02d', floor($data['total_work'] / 3600), floor(($data['total_work'] % 3600) / 60)));
            $reportData[$reportKey]['onsite'] = floatval(sprintf('%d.%02d', floor($data['onsite'] / 3600), floor(($data['onsite'] % 3600) / 60)));
            $reportData[$reportKey]['offsite'] = floatval(sprintf('%d.%02d', floor($data['offsite'] / 3600), floor(($data['offsite'] % 3600) / 60)));
        }

        // Step 2: Prepare the final pivot report
        $finalReport = [];

        foreach ($reportData as $userRow) {
            $row = [
                'name'       => $userRow['name'],
                'role'       => $userRow['role'],
                'team'       => $userRow['team'],
                'total_work' => $userRow['total_work'],
                'onsite'     => $userRow['onsite'],
                'offsite'    => $userRow['offsite'],
            ];

            // For each day, if total is 0, check if the day has any 'leave' type activity
            foreach ($dates as $date) {
                $val = $userRow['daily'][$date];
                if ((float)$val === 0.0) {
                    $label = 0;

                    foreach ($projectData as $entry) {
                        if (
                            $entry['username']  === $userRow['name'] &&
                            // $entry['team_name']=== $userRow['team']    &&
                            $entry['workdate'] === $date               &&
                            intval($entry['isLabled']) === 1
                        ) {
                            $label = $entry['label_symbol'];  // fetch directly from DB
                            break;
                        }
                    }
                    $row[$date] = $label;
                    
                } else {
                    // just keep the numeric total
                    $row[$date] = $val;
                }
            }

            $finalReport[] = $row;
        }

        // Step 4: sort by team name then username
        usort($finalReport, function ($a, $b) {
            $teamCmp = strcmp($a['team'], $b['team']);
            return $teamCmp === 0 ? strcmp($a['name'], $b['name']) : $teamCmp;
        });
        
        $this->appendTotalsRow($finalReport, $dates);
        return $finalReport;
    }

    /**
     * Appends a Totals row to $finalReport that sums total_work, onsite, offsite,
     * and numeric daily columns.
     */
    private function appendTotalsRow(array &$finalReport, array $dates): void
    {
        if (empty($finalReport)) {
            return;
        }

        // Accumulators in minutes
        $sumMinutes = [
            'total_work' => 0,
            'onsite'     => 0,
            'offsite'    => 0,
        ];
        // one per date
        $dateMinutes = array_fill_keys($dates, 0);

        // 1) convert each row's floats to minutes, and accumulate
        foreach ($finalReport as $row) {
            foreach (['total_work','onsite','offsite'] as $col) {
                if (isset($row[$col]) && is_numeric($row[$col])) {
                    $sumMinutes[$col] += $this->toMinutes($row[$col]);
                }
            }
            foreach ($dates as $d) {
                if (isset($row[$d]) && is_numeric($row[$d])) {
                    $dateMinutes[$d] += $this->toMinutes($row[$d]);
                }
            }
        }

        // 2) build the totals row, converting minutes back to H.MM floats
        $totalsRow = [
            'name'       => 'Totals',
            'role'       => '',
            'team'       => '',
            'total_work' => $this->toHourMin($sumMinutes['total_work']),
            'onsite'     => $this->toHourMin($sumMinutes['onsite']),
            'offsite'    => $this->toHourMin($sumMinutes['offsite']),
        ];
        foreach ($dates as $d) {
            $totalsRow[$d] = $this->toHourMin($dateMinutes[$d]);
        }

        $finalReport[] = $totalsRow;
    }

    // Sheet 2
    protected function prepareAllUsersReportSheet2(array $userIds, string $startDate, string $endDate, ?Project $project = null, ?Int $team = null, bool $crFilter): array
    {
        $projectData = $this->projectRepository->getAllUsersDailyProjectData($userIds, $startDate, $endDate, $project, $team, $crFilter);
        $transformedData = [];
        $dateWiseData = [];
    
        foreach ($projectData as $entry) {
            $workdate = $entry['workdate'];  // Already in Y-m-d format from DATE(t.start_time)
            $weekday  = $entry['weekday'];   // This comes from t.day in DB
            $username = $entry['username'];
            $projectName = $entry['project_name'];
            $secondsWorked = $entry['total_duration'];
            $jiraIds = $entry['jira_ids'];
            $description = $entry['description'];
            $component = $entry['component'];
    
            // Convert seconds to hours.
            $hoursWorked = floatval(sprintf('%d.%02d', floor($secondsWorked / 3600), floor(($secondsWorked % 3600) / 60)));
            
            $transformedData[] = [
                'name' => $username,
                'workdate' => (new \DateTime($workdate))->format('j-M-Y'), // Format the date to '1-Jan-2025'
                'weekday' => $weekday,
                'hours' => $hoursWorked,
                'project' => $projectName,
                'jira_ids' => $jiraIds,
                'description' => $description,
                'component' => $component,
            ];

        }
    
        return $transformedData;
    }	

    /**
    * Converts hour.decimal format (e.g., 7.5) to total minutes.
    */
    private function toMinutes($time): int
    {
        $hours = floor($time);
        $fraction = $time - $hours;
        $minutes = round($fraction * 100);
        return ($hours * 60) + $minutes;
    }

    /**
    * Converts total minutes to hour:minute format.
    */
    private function toHourMin(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return floatval(sprintf('%d.%02d', $hours, $mins));
    }
}
