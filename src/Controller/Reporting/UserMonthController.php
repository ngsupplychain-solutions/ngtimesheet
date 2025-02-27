<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Reporting;

use App\Entity\User;
use App\Export\Spreadsheet\Writer\BinaryFileResponseWriter;
use App\Export\Spreadsheet\Writer\XlsxWriter;
use App\Model\DailyStatistic;
use App\Reporting\MonthByUser\MonthByUser;
use App\Reporting\MonthByUser\MonthByUserForm;
use Exception;
use PhpOffice\PhpSpreadsheet\Reader\Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

#[Route(path: '/reporting/user')]
#[IsGranted('report:user')]
final class UserMonthController extends AbstractUserReportController
{
    /**
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    #[Route(path: '/month', name: 'report_user_month', methods: ['GET', 'POST'])]
    public function monthByUser(Request $request): Response
    {
        return $this->render('reporting/report_by_user.html.twig', $this->getData($request));
    }

    #[Route(path: '/month_export', name: 'report_user_month_export', methods: ['GET', 'POST'])]
    public function export(Request $request): Response
    {
        $data = $this->getData($request, true);
        $content = $this->renderView('reporting/report_by_user_data.html.twig', $data);

        $reader = new Html();
        $spreadsheet = $reader->loadFromString($content);

        $worksheet = $spreadsheet->getActiveSheet();

		// Apply header and data styling.
		$this->applyHeaderAndDataStyling($worksheet);

		// Color rows based on component and duration.
		$this->colorRowsByComponent($worksheet);

        $writer = new BinaryFileResponseWriter(new XlsxWriter(), 'kimai-export-user-monthly');
        return $writer->getFileResponse($spreadsheet);
    }

    private function getData(Request $request, bool $export = false): array
    {
        $currentUser = $this->getUser();
        $dateTimeFactory = $this->getDateTimeFactory($currentUser);
        $canChangeUser = $this->canSelectUser();

        $values = new MonthByUser();
        $values->setDecimal($export);
        $values->setUser($currentUser);
        $values->setDate($dateTimeFactory->getStartOfMonth());

        $form = $this->createFormForGetRequest(MonthByUserForm::class, $values, [
            'include_user' => $canChangeUser,
            'timezone' => $dateTimeFactory->getTimezone()->getName(),
            'start_date' => $values->getDate(),
        ]);

        $form->submit($request->query->all(), false);

        if ($values->getUser() === null) {
            $values->setUser($currentUser);
        }

        if ($currentUser !== $values->getUser() && !$canChangeUser) {
            throw new AccessDeniedException('User is not allowed to see other users timesheet');
        }

        if ($values->getDate() === null) {
            $values->setDate($dateTimeFactory->getStartOfMonth());
        }

        /** @var \DateTime $start */
        $start = $values->getDate();
        $start->modify('first day of 00:00:00');

        $end = clone $start;
        $end->modify('last day of 23:59:59');

        /** @var User $selectedUser */
        $selectedUser = $values->getUser();

        $previousMonth = clone $start;
        $previousMonth->modify('-1 month');

        $nextMonth = clone $start;
        $nextMonth->modify('+1 month');

        $data = $this->prepareReport($start, $end, $selectedUser);

        return [
            'decimal' => $values->isDecimal(),
            'dataType' => $values->getSumType(),
            'report_title' => 'report_user_month',
            'box_id' => 'user-month-reporting-box',
            'form' => $form->createView(),
            'rows' => $data,
            'period' => new DailyStatistic($start, $end, $selectedUser),
            'user' => $selectedUser,
            'current' => $start,
            'next' => $nextMonth,
            'previous' => $previousMonth,
            'begin' => $start,
            'end' => $end,
            'export_route' => 'report_user_month_export',
        ];
    }

    private function applyHeaderAndDataStyling(Worksheet $worksheet): void
	{
		// Determine the highest column and row in the worksheet.
		$highestColumn = $worksheet->getHighestColumn();
		$highestRow    = $worksheet->getHighestRow();

		// --- Header styling (Row 1) ---
		$headerRange = "A1:{$highestColumn}1";
		$worksheet->getStyle($headerRange)->applyFromArray([
			'fill' => [
				'fillType'   => Fill::FILL_SOLID,
				'startColor' => ['rgb' => 'C9DAF8'], // Header fill color
			],
			'font' => [
				'bold'  => true,
				'size'  => 12,
				'name'  => 'Aptos Narrow',
				'color' => ['rgb' => '000000'],
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

		// --- Data styling (Rows 2 to the last row) ---
		// You may adjust if your header spans more than one row.
		$dataRange = "A2:{$highestColumn}{$highestRow}";
		$worksheet->getStyle($dataRange)->applyFromArray([
			'fill' => [
				'fillType' => Fill::FILL_NONE,
			],
			'font' => [
				'size' => 11,
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
	}
	
	
	private function colorRowsByComponent(Worksheet $worksheet): void
	{
		// Map each component to a fill color (hex code without #).
		$componentColors = [
			'vacation' => 'C6E0B4',  
			'week off'  => 'D0CECE',  
			'week-off'  => 'D0CECE',
			'comp-off'  => 'FCE4D6',  
			'sick'     => 'FFC000',  
			'sick/emergency' => 'FFC000',
			'emergency' => 'FFC000',
		];

		// Determine the last row of data.
		$highestRow = $worksheet->getHighestRow();

		// Loop through each data row (assuming row 1 is header, so data starts at row 2)
		for ($row = 2; $row <= $highestRow; $row++) {
			// Get the "Component" from column C.
			$component = $worksheet->getCell("C{$row}")->getValue();
			$component = strtolower(trim($component));

			// Get the "Duration" from column F and convert to a float.
			$duration = (float)$worksheet->getCell("F{$row}")->getValue();

			// If the component is one of our keys and duration equals 0, apply the fill.
			if (isset($componentColors[$component]) && $duration === 0.0) {
				// Define the range to fill (adjust the range as needed; here we fill columns A to H).
				$range = "A{$row}:H{$row}";
				$worksheet->getStyle($range)->applyFromArray([
					'fill' => [
						'fillType'   => Fill::FILL_SOLID,
						'startColor' => ['rgb' => $componentColors[$component]],
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
			}
		}
    }

}
