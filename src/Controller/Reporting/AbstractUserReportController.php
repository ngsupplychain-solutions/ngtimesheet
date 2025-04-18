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
            $hoursWorked = ($secondsWorked / 3600);

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

    // Sheet 1
    protected function prepareAllUsersReport(array $userIds, string $startDate, string $endDate, ?Project $project = null, bool $crFilter): array
    {
        $projectData = $this->projectRepository->getAllUsersProjectData($userIds, $startDate, $endDate, $project, $crFilter);

        $reportData = [];
        
        // Collect all dates for the reporting period
        $dates = [];
        $current = new \DateTime($startDate);
        $endDt = new \DateTime($endDate);
        while ($current <= $endDt) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }

        // Step 1: Group data by user
        foreach ($projectData as $entry) {
            $username = $entry['username'];
            $role = $entry['role'] ?? 'N/A';
            $workdate = $entry['workdate'];
            $onsiteDuration = ($entry['onsite_duration']/3600);
            $offsiteDuration = ($entry['offsite_duration']/3600);
            $totalDuration = ($entry['total_duration']/3600);
            $activityName = strtolower(trim($entry['activity_name'] ?? ''));

            // Assume $entry contains a user_id field. If not, you might need to add it in your query.
            $userId = $entry['user_id'] ?? null;

            if (!isset($reportData[$username])) {
                $teamNames = [];
                if ($userId !== null) {
                    $teams = $this->projectRepository->getTeamsForUser($userId, $project ? $project->getId() : null);
                    $teamNames = array_map(function ($team) {
                        return $team['name'];
                    }, $teams);
                }
                $team = !empty($teamNames) ? implode(', ', $teamNames) : 'N/A';

                 // Initialize daily arrays
                $reportData[$username] = [
                    'name'       => $username,
                    'role'       => $role,
                    'team'       => $team,
                    'total_work' => 0,
                    'onsite'     => 0,
                    'offsite'    => 0,
                    'daily'      => array_fill_keys($dates, 0),
                     // store all activity names for each day
                    'activities' => array_fill_keys($dates, [])
                ];
            }
            // Accumulate totals
            $reportData[$username]['total_work'] += $totalDuration;
            $reportData[$username]['onsite'] += $onsiteDuration;
            $reportData[$username]['offsite'] += $offsiteDuration;

             // Accumulate daily total
            $reportData[$username]['daily'][$workdate] += $totalDuration;

            // Save the activity name for that day
            $reportData[$username]['activities'][$workdate][] = $activityName;
        }

        // Step 2: Build final pivot and check for 'leave' codes
        // define the short codes for your known activities
        $leaveMap = [
            'week off'      => 'W',
            'week-off'      => 'W',
            'comp-off'      => 'C',
            'comp off'      => 'C',
            'vacation'      => 'V',
            'sick'          => 'S',
            'emergency'     => 'S',
            'sick/emergency'=> 'S',
            'change request' => 'CR',
            'changerequest' => 'CR'
        ];

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
                if ($val === 0) {
                    // see if any activity is in leaveMap
                    $dayActivities = $userRow['activities'][$date] ?? [];
                    // default code is 0 if no recognized leave found
                    $code = 0;
                    foreach ($dayActivities as $act) {
                        if (isset($leaveMap[$act])) {
                            $code = $leaveMap[$act];
                            break;
                        }
                    }
                    $row[$date] = $code; // either 0 or 'W','C','S','V','CR'
                } else {
                    // just keep the numeric total
                    $row[$date] = $val;
                }
            }

            $finalReport[] = $row;
        }

        // Order by Teams and name of report
            usort($finalReport, function($a, $b) {
                // Compare team first
                $teamComparison = strcmp($a['team'], $b['team']);
                if ($teamComparison === 0) {
                    // If teams are the same, compare names
                    return strcmp($a['name'], $b['name']);
                }
                return $teamComparison;
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
        // If no data, do nothing
        if (empty($finalReport)) {
            return;
        }

        // 1) Create an empty totals row
        $totalsRow = [
            'name'       => 'Totals',
            'role'       => '',
            'team'       => '',
            'total_work' => 0,
            'onsite'     => 0,
            'offsite'    => 0,
        ];

        // Initialize each date column
        foreach ($dates as $d) {
            $totalsRow[$d] = 0;
        }

        // 2) Accumulate sums
        foreach ($finalReport as $row) {

            // Skip if this row is already a totals row (if that's possible)
            // if ($row['name'] === 'Totals') {
            //     continue;
            // }

            // sum monthly columns if numeric
            if (isset($row['total_work']) && is_numeric($row['total_work'])) {
                $totalsRow['total_work'] += $row['total_work'];
            }
            if (isset($row['onsite']) && is_numeric($row['onsite'])) {
                $totalsRow['onsite'] += $row['onsite'];
            }
            if (isset($row['offsite']) && is_numeric($row['offsite'])) {
                $totalsRow['offsite'] += $row['offsite'];
            }

            // sum each date column if numeric
            foreach ($dates as $d) {
                if (isset($row[$d]) && is_numeric($row[$d])) {
                    $totalsRow[$d] += $row[$d];
                }
            }
        }

        // 3) Append the totals row to $finalReport
        $finalReport[] = $totalsRow;
    }

    // Sheet 2
    protected function prepareAllUsersReportSheet2(array $userIds, string $startDate, string $endDate, ?Project $project = null, bool $crFilter): array
    {
        $projectData = $this->projectRepository->getAllUsersDailyProjectData($userIds, $startDate, $endDate, $project, $crFilter);
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
            $hoursWorked = $secondsWorked / 3600;
            
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
	
}
