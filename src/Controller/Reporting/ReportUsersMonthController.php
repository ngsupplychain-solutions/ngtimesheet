<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Reporting;

use App\Controller\AbstractController;
use App\Export\Spreadsheet\Writer\BinaryFileResponseWriter;
use App\Export\Spreadsheet\Writer\XlsxWriter;
use App\Model\DailyStatistic;
use App\Reporting\MonthlyUserList\MonthlyUserList;
use App\Reporting\MonthlyUserList\MonthlyUserListForm;
use App\Repository\Query\TimesheetStatisticQuery;
use App\Repository\Query\UserQuery;
use App\Repository\Query\VisibilityInterface;
use App\Repository\UserRepository;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Timesheet\TimesheetStatisticService;
use PhpOffice\PhpSpreadsheet\Reader\Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Style\ExportUsersStyle;
use App\Style\ExportStyle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

#[Route(path: '/reporting/users')]
#[IsGranted('report:other')]
final class ReportUsersMonthController extends AbstractUserReportController
{
    private ProjectRepository $projectRepository;
    private UserRepository $userRepository;

    // The constructor now must accept the dependencies required by the parent.
    public function __construct(
        TimesheetStatisticService $statisticService,
        ProjectRepository $projectRepository,
        ActivityRepository $activityRepository,
        UserRepository $userRepository
    ) {
        // Pass the required dependencies to the parent constructor.
        parent::__construct($statisticService, $projectRepository, $activityRepository);
        $this->userRepository = $userRepository;
        $this->projectRepository = $projectRepository;
    }

    #[Route(path: '/month', name: 'report_monthly_users', methods: ['GET', 'POST'])]
    public function report(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository): Response
    {
        return $this->render(
            'reporting/report_user_list.html.twig',
            $this->getData($request, $statisticService, $userRepository, false)
        );
    }

    #[Route(path: '/month_export', name: 'report_monthly_users_export', methods: ['GET', 'POST'])]
    public function export(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository): Response
    {
        $dataFormat1 = $this->getData($request, $statisticService, $userRepository, false);
        $contentFormat1 = $this->renderView('reporting/report_user_list_export.html.twig', $dataFormat1);

        $dataFormat2 = $this->getDataSheet2($request, $statisticService, $userRepository);
        $contentFormat2 = $this->renderView('reporting/report_by_users_data_sheet2.html.twig', $dataFormat2);

		$spreadsheet = new Spreadsheet();
        $reader = new Html();

        //---------------------Style for Sheet1-----------------
		$spreadsheet = $reader->loadFromString($contentFormat1);
		$sheet1 = $spreadsheet->getActiveSheet();
		$sheet1->setTitle('MonthlyReport');

		// Apply all header design and conditional formatting in one function.
		ExportStyle::applyExportDesign($sheet1);
		
		// Apply total row styling.
		ExportStyle::applyTotalRowStyle($sheet1);
		
		// Finally, append the legend at the bottom-left
		ExportStyle::addLegend($sheet1);

        //--------------------Style for Sheet2-----------------
		// Create a new worksheet for the second format.
		$sheet2 = new Worksheet($spreadsheet, 'UsersReport');
		$spreadsheet->addSheet($sheet2, 1); // Insert at position 1 or at the end
		
		$tempSpreadsheet = $reader->loadFromString($contentFormat2);
		$tempSheet = $tempSpreadsheet->getActiveSheet();

        $sheetData = $tempSheet->toArray();
		$sheet2->fromArray($sheetData, null, 'A1');

        ExportUsersStyle::applyHeaderAndDataStyling($sheet2);
		ExportUsersStyle::colorRowsByComponent($sheet2);
		//----------------------------------------------------


        $writer = new BinaryFileResponseWriter(new XlsxWriter(), 'Export-users-monthly');
        return $writer->getFileResponse($spreadsheet);
    }

    private function getData(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository, bool $crFilter = true): array
    {
        $currentUser = $this->getUser();
        $dateTimeFactory = $this->getDateTimeFactory();

        $values = new MonthlyUserList();
        $values->setDate($dateTimeFactory->getStartOfMonth());

        $form = $this->createFormForGetRequest(MonthlyUserListForm::class, $values, [
            'timezone' => $dateTimeFactory->getTimezone()->getName(),
            'start_date' => $values->getDate(),
        ]);

        $form->submit($request->query->all(), false);

        $query = new UserQuery();
        $query->setVisibility(VisibilityInterface::SHOW_BOTH);
        $query->setSystemAccount(false);
        $query->setCurrentUser($currentUser);

        $projectId = $request->query->getInt('project', 0);  // 0 means not provided
        $teamId    = $request->query->getInt('team', 0);

        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                $values->setDate($dateTimeFactory->getStartOfMonth());
            } else {
                if ($values->getTeam() !== null) {
                    $query->setSearchTeams([$values->getTeam()]);
                }
            }
        }

        $allUsers = $userRepository->getUsersForQuery($query);
        $userIds = array_map(fn($user) => $user->getId(), $allUsers);

        if ($values->getDate() === null) {
            $values->setDate($dateTimeFactory->getStartOfMonth());
        }

        /** @var \DateTime $start */
        $start = $values->getDate();
        $start->modify('first day of 00:00:00');

        $end = clone $start;
        $end->modify('last day of 23:59:59');

        $previous = clone $start;
        $previous->modify('-1 month');

        $next = clone $start;
        $next->modify('+1 month');

        // Optional: if a specific project is provided, get it
        $selectedProject = $values->getProject();

        $reportData = $this->prepareAllUsersReport(
            $userIds,
            $start->format('Y-m-d H:i:s.u'),
            $end->format('Y-m-d H:i:s.u'),
            $selectedProject,
            $teamId > 0 ? $teamId : null,
            $crFilter
        );
        
        return [
            'period_attribute' => 'days',
            'dataType' => $values->getSumType(),
            'report_title' => 'report_monthly_users',
            'box_id' => 'monthly-user-list-reporting-box',
            'export_route' => 'report_monthly_users_export',
            'form' => $form->createView(),
            'current' => $start,
            'next' => $next,
            'previous' => $previous,
            'decimal' => $values->isDecimal(),
            'subReportDate' => $values->getDate(),
            'subReportRoute' => 'report_user_month',
            'stats' => $reportData,
            'hasData' => !empty($reportData),
        ];
    }

    private function getDataSheet2(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository, bool $crFilter = true): array
    {
        $currentUser = $this->getUser();
        $dateTimeFactory = $this->getDateTimeFactory();

        $values = new MonthlyUserList();
        $values->setDate($dateTimeFactory->getStartOfMonth());

        $form = $this->createFormForGetRequest(MonthlyUserListForm::class, $values, [
            'timezone' => $dateTimeFactory->getTimezone()->getName(),
            'start_date' => $values->getDate(),
        ]);

        $form->submit($request->query->all(), false);

        $query = new UserQuery();
        $query->setVisibility(VisibilityInterface::SHOW_BOTH);
        $query->setSystemAccount(false);
        $query->setCurrentUser($currentUser);

        $projectId = $request->query->getInt('project', 0);  // 0 means not provided
        $teamId    = $request->query->getInt('team', 0);

        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                $values->setDate($dateTimeFactory->getStartOfMonth());
            } else {
                if ($values->getTeam() !== null) {
                    $query->setSearchTeams([$values->getTeam()]);
                }
            }
        }

        $allUsers = $userRepository->getUsersForQuery($query);
        $userIds = array_map(fn($user) => $user->getId(), $allUsers);

        if ($values->getDate() === null) {
            $values->setDate($dateTimeFactory->getStartOfMonth());
        }

        /** @var \DateTime $start */
        $start = $values->getDate();
        $start->modify('first day of 00:00:00');

        $end = clone $start;
        $end->modify('last day of 23:59:59');

        $previous = clone $start;
        $previous->modify('-1 month');

        $next = clone $start;
        $next->modify('+1 month');

        // Optional: if a specific project is provided, get it
        $selectedProject = $values->getProject();

        $reportData = $this->prepareAllUsersReportSheet2(
            $userIds,
            $start->format('Y-m-d H:i:s.u'),
            $end->format('Y-m-d H:i:s.u'),
            $selectedProject,
            $teamId > 0 ? $teamId : null,
            $crFilter
        );
        
        return [
            'period_attribute' => 'days',
            'dataType' => $values->getSumType(),
            'report_title' => 'report_monthly_users',
            'box_id' => 'monthly-user-list-reporting-box',
            'export_route' => 'report_monthly_users_export',
            'form' => $form->createView(),
            'current' => $start,
            'next' => $next,
            'previous' => $previous,
            'decimal' => $values->isDecimal(),
            'subReportDate' => $values->getDate(),
            'subReportRoute' => 'report_user_month',
            'stats' => $reportData,
            'hasData' => !empty($reportData),
        ];
    }

}
