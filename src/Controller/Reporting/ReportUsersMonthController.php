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

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

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
            $this->getData($request, $statisticService, $userRepository)
        );
    }

    #[Route(path: '/month_export', name: 'report_monthly_users_export', methods: ['GET', 'POST'])]
    public function export(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository): Response
    {
        $data = $this->getData($request, $statisticService, $userRepository);
        $content = $this->renderView('reporting/report_user_list_export.html.twig', $data);

        $reader = new Html();
        $spreadsheet = $reader->loadFromString($content);

        //-------------------------------------------------------
		// Get the active worksheet.
		$worksheet = $spreadsheet->getActiveSheet();

		// Apply all header design and conditional formatting in one function.
		$this->applyExportDesign($worksheet);
		
		// Apply total row styling.
		$this->applyTotalRowStyle($worksheet);
		
		// Finally, append the legend at the bottom-left
		$this->addLegend($worksheet);
		//-----------------------------------------------------

        $writer = new BinaryFileResponseWriter(new XlsxWriter(), 'kimai-export-users-monthly');
        return $writer->getFileResponse($spreadsheet);
    }

    private function getData(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository): array
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
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $selectedProject
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

    /**
     * Applies header design and conditional formatting to the export worksheet.
     *
     * This function does the following:
     * - Applies a blue background with white, bold text (centered) to row 1 (main header).
     * - Applies a gray background with white, bold text (centered) to row 2 (weekday header).
     * - Computes the dynamic range for daily data (starting from row 3, after fixed columns).
     * - Adds conditional formatting rules for cells containing "V", "W", "C", or "S".
     *
     * @param Worksheet $worksheet The active worksheet to which the design will be applied.
     */
    private function applyExportDesign(Worksheet $worksheet): void
    {
        // --- Apply header styling for row 1 (main header) ---  E1EAFB
        $highestColumn = $worksheet->getHighestColumn();
        
        // Define common header style.
        $headerStyle = [
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => '000000'],
                'size'  => 12,
                'name'  => 'Aptos Narrow',
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => '000000'],
                ],
            ],
            'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'C9DAF8'], 
                ],
        ];
        $headerRange = "A1:{$highestColumn}1";
        $worksheet->getStyle($headerRange)->applyFromArray($headerStyle);

        // --- Apply header styling for row 2 (weekday header) ---
        $weekdayRange = "A2:{$highestColumn}2";
        $worksheet->getStyle($weekdayRange)->applyFromArray($headerStyle);
        
        $worksheet->getParent()->getDefaultStyle()->getFont()->setName('Aptos Narrow');  //for default font style

        // --- Determine dynamic range for daily data and apply conditional formatting ---
        
        $startRow = 3;
        $highestRow = $worksheet->getHighestRow();
        $dataRange = "A{$startRow}:{$highestColumn}{$highestRow}";
        $worksheet->getStyle($dataRange)->applyFromArray([
            'font' => [
                'size' => 12,
                'name' => 'Calibri',
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => '000000'],
                ],
            ],
        ]);
        
        $fixedColumns = 6;      //   - The first 6 columns (A–F) are fixed.
        $startColumn = Coordinate::stringFromColumnIndex($fixedColumns + 1); // e.g., column 'G'
        $highestColumn = $worksheet->getHighestColumn(); // Last column (as set by your HTML view)
        $dataRange = $startColumn . $startRow . ':' . $highestColumn . $highestRow;

        // --- Define conditional formatting rules ---
        // For cells containing "V" (Vacation) – Light Green
        $conditionalV = new Conditional();
        $conditionalV->setConditionType(Conditional::CONDITION_CONTAINSTEXT)
                    ->setOperatorType(Conditional::OPERATOR_CONTAINSTEXT)
                    ->setText('V');
        $conditionalV->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('C6E0B4');

        // For cells containing "W" (Week Off) – Light Grey
        $conditionalW = new Conditional();
        $conditionalW->setConditionType(Conditional::CONDITION_CONTAINSTEXT)
                    ->setOperatorType(Conditional::OPERATOR_CONTAINSTEXT)
                    ->setText('W');
        $conditionalW->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('D0CECE');

        // For cells containing "C" (Comp Off) – Light Red
        $conditionalC = new Conditional();
        $conditionalC->setConditionType(Conditional::CONDITION_CONTAINSTEXT)
                    ->setOperatorType(Conditional::OPERATOR_CONTAINSTEXT)
                    ->setText('C');
        $conditionalC->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FCE4D6');

        // For cells containing "S" (Sick/Emergency Leave) – Light Yellow
        $conditionalS = new Conditional();
        $conditionalS->setConditionType(Conditional::CONDITION_CONTAINSTEXT)
                    ->setOperatorType(Conditional::OPERATOR_CONTAINSTEXT)
                    ->setText('S');
        $conditionalS->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFC000');

        // --- Append conditional formatting rules to the computed data range ---
        $conditionalStyles = $worksheet->getStyle($dataRange)->getConditionalStyles();
        $conditionalStyles[] = $conditionalV;
        $conditionalStyles[] = $conditionalW;
        $conditionalStyles[] = $conditionalS;
        $conditionalStyles[] = $conditionalC;
        $worksheet->getStyle($dataRange)->setConditionalStyles($conditionalStyles);
    }
        private function applyTotalRowStyle(Worksheet $worksheet): void
    {
        // Get the highest column and row from the worksheet.
        $highestColumn = $worksheet->getHighestColumn();
        $highestRow = $worksheet->getHighestRow();

        // Define the range for the total row.
        $totalRowRange = "A{$highestRow}:{$highestColumn}{$highestRow}";

        // Apply the style to the total row.
        $worksheet->getStyle($totalRowRange)->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D3D3D3'], // Change this color code as desired.
            ],
            'font' => [
                'bold' => true,
                // Optionally set a different size or font name here.
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => '000000'],
                ],
            ],
        ]);
    }

    private function addLegend(Worksheet $worksheet): void
    {
        // Figure out where your data ends so the legend is placed after everything
        $highestRow = $worksheet->getHighestRow();
        // Start legend a couple rows below the data
        $legendStartRow = $highestRow + 2;

        // Define each legend row: label + color
        $legendItems = [
            // label, fill color
            ['W - Week Off',           'D0CECE'],  // Light grey
            ['C - Comp Off',           'FCE4D6'],  // Light peach (adjust as needed)
            ['S - Sick/Emergency Leave','FFC000'], // Yellow
            ['V - Vacation',           'C6E0B4'],  // Light green
        ];

        foreach ($legendItems as $index => [$label, $color]) {
            // Calculate the row number for this legend item
            $rowNumber = $legendStartRow + $index;
            // We’ll just put everything in column B; adjust as needed
            $cellAddress = "B{$rowNumber}";

            // Set the cell value to your label
            $worksheet->setCellValue($cellAddress, $label);

            // Apply fill color and border around that cell
            $worksheet->getStyle($cellAddress)->applyFromArray([
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $color],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => '000000'],
                    ],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
                'font' => [
                    'bold' => true,
                    'size' => 11,
                    // 'name' => 'Calibri', // or any font you want
                ],
            ]);

            // Optionally, if you want each legend cell to be a certain width,
            // you can set column width:
            $worksheet->getColumnDimension('B')->setWidth(30);
        }
    }

}
