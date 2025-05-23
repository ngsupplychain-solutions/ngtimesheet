<?php

namespace App\Style;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ExportUsersStyle
{
     /**
     * Apply common header and data styling for users.
     * This function styles the header row (A1:HighestColumn1)
     * and the data rows (A2:HighestColumnHighestRow) with borders,
     * fonts, alignments, and fill color for headers.
     */
    public static function applyHeaderAndDataStyling(Worksheet $worksheet): void
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


    /**
     * Apply color to rows based on a "Component" value (in column C)
     * and a "Duration" value (in column F) when the duration is 0.
     * Different components receive different fill colors.
     */
    public static function colorRowsByComponent(Worksheet $worksheet): void
	{
		// Map each component to a fill color (hex code without #).
		$componentColors = [
			'vacation' => 'C6E0B4',  // Light yellow
			'week off'  => 'D0CECE',  // Light red/pink
			'week-off'  => 'D0CECE',
			'comp-off'  => 'FCE4D6',  // Light pinkish
			'sick'     => 'FFC000',  // Light orange
			'sick/emergency' => 'FFC000',
			'emergency' => 'FFC000',
			'cr' => 'E52020',
		];

		// Determine the last row of data.
		$highestRow = $worksheet->getHighestRow();

		// Loop through each data row (assuming row 1 is header, so data starts at row 2)
		for ($row = 2; $row <= $highestRow; $row++) {
			// Get the "Component" from column B.
			$component = $worksheet->getCell("B{$row}")->getValue();
			$component = strtolower(trim($component));

			// Get the "Duration" from column F and convert to a float.
			$duration = (float)$worksheet->getCell("G{$row}")->getValue();

			// If the component is one of our keys and duration equals 0, apply the fill.
			if ((isset($componentColors[$component]) && $duration === 0.0)||(isset($componentColors[$component]))) {
				// Define the range to fill (adjust the range as needed; here we fill columns A to G).
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