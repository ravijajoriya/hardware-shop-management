<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * The product spreadsheet: its columns, reading an uploaded file into rows, and the template a
 * shopkeeper fills in. Columns are found by their heading, so their order does not matter and
 * columns the app does not know are left out.
 */
final class ProductSheet
{
    /** The most product rows one file may hold. */
    public const MAX_ROWS = 2000;

    /**
     * Each column: its heading, whether every product needs it, other headings taken to mean
     * the same, what to write in it, and an example.
     *
     * @var array<string, array{heading: string, needed: bool, also: list<string>, help: string, example: string|int|float}>
     */
    public const COLUMNS = [
        'name' => ['heading' => 'Product name', 'needed' => true, 'also' => ['name', 'product'], 'help' => 'The product as you sell it. Rows with the same name become sizes of one product.', 'example' => 'Apex Exterior Emulsion'],
        'size' => ['heading' => 'Size or variant', 'needed' => false, 'also' => ['size', 'variant'], 'help' => 'Only when it comes in several sizes or colours: one row for each, such as 1 L and 4 L.', 'example' => '1 L'],
        'category' => ['heading' => 'Category', 'needed' => true, 'also' => [], 'help' => 'For a subcategory write its parent first, then >, such as Paints > Exterior. Categories not in the app yet are added.', 'example' => 'Paints > Exterior'],
        'brand' => ['heading' => 'Brand', 'needed' => false, 'also' => [], 'help' => 'Leave blank when it has none. Brands not in the app yet are added.', 'example' => 'Asian Paints'],
        'unit' => ['heading' => 'Unit', 'needed' => true, 'also' => [], 'help' => 'How it is counted and sold: one of the units listed below, such as pc, kg or ltr.', 'example' => 'ltr'],
        'pack' => ['heading' => 'Bought in', 'needed' => false, 'also' => ['pack', 'pack unit'], 'help' => 'Only for something bought in packs and sold loose, such as wire bought in coils and sold by the metre: the pack, such as coil, bag or box. Leave blank otherwise.', 'example' => 'coil'],
        'pack_size' => ['heading' => 'Pack size', 'needed' => false, 'also' => ['in one pack', 'per pack'], 'help' => 'With Bought in: how many of the unit are in one pack, such as 90 (metres in a coil).', 'example' => 90],
        'sku' => ['heading' => 'Item code (SKU)', 'needed' => false, 'also' => ['sku', 'item code', 'code'], 'help' => 'Your own code for it. Optional, and each one must be different.', 'example' => 'APX-1L'],
        'purchase_price' => ['heading' => 'Purchase price', 'needed' => true, 'also' => ['cost price', 'cost', 'buying price'], 'help' => 'What one unit costs you to buy, in rupees. With Bought in, what one pack costs you.', 'example' => 300],
        'selling_price' => ['heading' => 'Selling price', 'needed' => true, 'also' => ['price', 'sale price', 'rate'], 'help' => 'What you sell one unit for, in rupees. With Bought in, this is the loose price, such as the price of one metre.', 'example' => 360],
        'pack_price' => ['heading' => 'Pack price', 'needed' => false, 'also' => ['full pack price'], 'help' => 'With Bought in: what a full pack sells for. Blank means the selling price times the pack size.', 'example' => 1350],
        'mrp' => ['heading' => 'MRP', 'needed' => false, 'also' => [], 'help' => 'The price printed on the pack. It cannot be below the selling price.', 'example' => 399],
        'stock' => ['heading' => 'Opening stock', 'needed' => true, 'also' => ['stock', 'quantity', 'qty'], 'help' => 'How many you have now. Whole numbers for units such as pc and box. With Bought in, the full packs you have.', 'example' => 10],
        'loose_stock' => ['heading' => 'Loose stock', 'needed' => false, 'also' => ['loose'], 'help' => 'With Bought in: what you have loose besides the full packs, such as 85 (metres left on an open coil).', 'example' => 85],
        'min_stock' => ['heading' => 'Minimum stock', 'needed' => false, 'also' => ['min stock', 'reorder level'], 'help' => 'Below this it shows as low on stock. Leave blank for no warning. With Bought in, counted in packs.', 'example' => 4],
        'area' => ['heading' => 'Shop or godown', 'needed' => false, 'also' => ['kept at', 'shop godown', 'area'], 'help' => 'Where it is kept: Shop or Godown. Blank means Shop.', 'example' => 'Godown'],
        'floor' => ['heading' => 'Floor', 'needed' => false, 'also' => [], 'help' => 'Ground, or a floor number such as 1.', 'example' => 1],
        'rack' => ['heading' => 'Rack', 'needed' => false, 'also' => [], 'help' => 'The rack, such as 3 or A. It needs the floor.', 'example' => 3],
        'shelf' => ['heading' => 'Shelf', 'needed' => false, 'also' => [], 'help' => 'The shelf number on that rack. It needs the rack.', 'example' => 2],
        'hsn' => ['heading' => 'HSN code', 'needed' => false, 'also' => ['hsn'], 'help' => 'The government code for the type of goods: 4, 6 or 8 digits.', 'example' => '3209'],
        'gst' => ['heading' => 'GST rate (%)', 'needed' => false, 'also' => ['gst', 'gst rate', 'gst percent'], 'help' => 'The GST rate, such as 18. Leave blank if none applies.', 'example' => 18],
        'description' => ['heading' => 'Description', 'needed' => false, 'also' => [], 'help' => 'Anything else about it.', 'example' => 'Weatherproof wall paint.'],
        'active' => ['heading' => 'Active', 'needed' => false, 'also' => [], 'help' => 'Yes, or No for a product you no longer sell. Blank means Yes.', 'example' => 'Yes'],
    ];

    /** Example rows for the template: a paint in two sizes, a hammer, and nails sold by weight. */
    private const EXAMPLES = [
        ['Apex Exterior Emulsion', '1 L', 'Paints > Exterior', 'Asian Paints', 'ltr', '', '', 'APX-1L', 300, 360, '', 399, 10, '', 4, 'Godown', 1, 3, 2, '3209', 18, 'Weatherproof wall paint.', 'Yes'],
        ['Apex Exterior Emulsion', '4 L', 'Paints > Exterior', 'Asian Paints', 'ltr', '', '', 'APX-4L', 1100, 1350, '', 1499, 6, '', 2, 'Godown', 1, 3, 2, '3209', 18, 'Weatherproof wall paint.', 'Yes'],
        ['Claw Hammer', '', 'Hand Tools', 'Stanley', 'pc', '', '', 'HAM-500', 250, 320, '', 350, 15, '', 5, 'Shop', 'Ground', 'A', 1, '8205', 18, '', 'Yes'],
        ['Wire Nails', '2 inch', 'Hardware > Nails', '', 'kg', '', '', '', 90, 120, '', '', 25.5, '', 10, 'Shop', 'Ground', 'B', 4, '7317', 18, '', 'Yes'],
        // Bought in 90 m coils at ₹1,000 and sold by the metre at ₹18, or ₹1,350 a coil: 5 coils
        // and 85 m from an open one, with a warning below 2 coils.
        ['Copper Wire', '1 mm', 'Electricals > Wires', 'Polycab', 'm', 'coil', 90, 'WIRE-1MM', 1000, 18, 1350, '', 5, 85, 2, 'Shop', 'Ground', 'C', 1, '8544', 18, 'Sold by the metre or by the coil.', 'Yes'],
    ];

    /**
     * Read the products from an uploaded file: its first sheet, or the one named Products.
     *
     * @return array{rows: list<array{row: int, values: array<string, mixed>}>, ignored: list<string>}
     *
     * @throws ValidationException When the file cannot be read or has no products to import.
     */
    public static function read(string $path, string $extension): array
    {
        try {
            $reader = IOFactory::createReader(match (strtolower($extension)) {
                'xls' => 'Xls',
                'csv', 'txt' => 'Csv',
                default => 'Xlsx',
            });
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            self::fail('This file cannot be read. Save it as an Excel file (.xlsx) and try again.');
        }

        $sheet = $spreadsheet->getSheetByName('Products') ?? $spreadsheet->getSheet(0);
        $cells = $sheet->toArray(null, true, false, false);

        $headingRow = null;
        $keys = [];
        $ignored = [];

        foreach ($cells as $index => $cellsOfRow) {
            if (self::isBlank($cellsOfRow)) {
                continue;
            }

            $headingRow = $index;

            foreach ($cellsOfRow as $column => $heading) {
                $text = trim((string) $heading);

                if ($text === '') {
                    continue;
                }

                $key = self::keyFor($text);

                if ($key !== null && ! in_array($key, $keys, true)) {
                    $keys[$column] = $key;
                } else {
                    $ignored[] = $text;
                }
            }

            break;
        }

        $missing = array_diff(
            array_keys(array_filter(self::COLUMNS, fn (array $column) => $column['needed'])),
            $keys,
        );

        if ($headingRow === null || $missing) {
            self::fail(
                $headingRow === null
                    ? 'The file is empty. Download the template, fill it in and upload it again.'
                    : 'These columns are missing: '.implode(', ', array_map(fn (string $key) => self::COLUMNS[$key]['heading'], $missing))
                        .'. Download the template to see every column.',
            );
        }

        $rows = [];

        foreach (array_slice($cells, $headingRow + 1, null, true) as $index => $cellsOfRow) {
            $values = [];

            foreach ($keys as $column => $key) {
                $values[$key] = $cellsOfRow[$column] ?? null;
            }

            if (self::isBlank($values)) {
                continue;
            }

            // Spreadsheet rows count from 1, as the shopkeeper sees them.
            $rows[] = ['row' => $index + 1, 'values' => $values];
        }

        if ($rows === []) {
            self::fail('The file has no products under its headings.');
        }

        if (count($rows) > self::MAX_ROWS) {
            self::fail('The file has '.number_format(count($rows)).' product rows. Import up to '
                .number_format(self::MAX_ROWS).' at a time by splitting it into smaller files.');
        }

        return ['rows' => $rows, 'ignored' => $ignored];
    }

    /**
     * The template to fill in, as the contents of an .xlsx file: a Products sheet with the
     * headings (needed ones marked *) and lists to pick from, an Example sheet, and a Help
     * sheet explaining every column with the units, categories and brands already in the app.
     *
     * @param  list<array{short_name: string, name: string, allow_decimal?: bool}>  $units
     * @param  list<string>  $categories  Category paths, such as Paints > Exterior.
     * @param  list<string>  $brands
     */
    public static function template(array $units, array $categories, array $brands): string
    {
        $spreadsheet = new Spreadsheet;
        $headings = array_map(
            fn (array $column) => $column['heading'].($column['needed'] ? ' *' : ''),
            array_values(self::COLUMNS),
        );

        $products = $spreadsheet->getActiveSheet()->setTitle('Products');
        self::writeTable($products, $headings, []);
        $products->freezePane('A2');

        $lastRow = self::MAX_ROWS + 1;
        $choices = [
            'unit' => array_column($units, 'short_name'),
            // Packs are counted whole: box, bag, coil and the like.
            'pack' => array_column(array_filter($units, fn (array $unit) => ! ($unit['allow_decimal'] ?? false)), 'short_name'),
            'area' => ['Shop', 'Godown'],
            'active' => ['Yes', 'No'],
        ];

        foreach ($choices as $key => $list) {
            $formula = '"'.implode(',', $list).'"';

            // Excel only takes a typed list of up to 255 characters.
            if ($list === [] || strlen($formula) > 255) {
                continue;
            }

            $letter = self::letter($key);
            $validation = $products->getCell("{$letter}2")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(false)
                ->setFormula1($formula);
            $validation->setSqref("{$letter}2:{$letter}{$lastRow}");
        }

        self::writeTable($spreadsheet->createSheet()->setTitle('Example'), $headings, self::EXAMPLES);

        $help = $spreadsheet->createSheet()->setTitle('Help');
        $lines = [
            ['How to fill in the Products sheet'],
            ['One row for each product. A product sold in several sizes or colours gets one row for each, with the same product name.'],
            ['Columns marked * are needed. The order of the columns does not matter.'],
            ['Bought in packs and sold loose, such as wire in 90 m coils sold by the metre? Fill in Bought in (coil) and Pack size (90). Then write the purchase price of one coil, the selling price of one metre, the full coils you have in Opening stock and any loose metres in Loose stock.'],
            [],
            ['Column', 'Needed', 'What to write', 'Example'],
        ];

        foreach (self::COLUMNS as $column) {
            $lines[] = [$column['heading'], $column['needed'] ? 'Yes' : 'No', $column['help'], $column['example']];
        }

        $lines[] = [];
        $lines[] = ['Units in the app'];

        foreach ($units as $unit) {
            $lines[] = [$unit['short_name'], $unit['name']];
        }

        $lines[] = [];
        $lines[] = ['Categories in the app'];

        foreach ($categories ?: ['None yet'] as $category) {
            $lines[] = [$category];
        }

        $lines[] = [];
        $lines[] = ['Brands in the app'];

        foreach ($brands ?: ['None yet'] as $brand) {
            $lines[] = [$brand];
        }

        $help->fromArray($lines, null, 'A1', true);
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getStyle('A5:D5')->getFont()->setBold(true);

        foreach (['A' => 22, 'B' => 10, 'C' => 90, 'D' => 26] as $letter => $width) {
            $help->getColumnDimension($letter)->setWidth($width);
        }

        $spreadsheet->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    private static function writeTable(Worksheet $sheet, array $headings, array $rows): void
    {
        $sheet->fromArray([$headings, ...$rows], null, 'A1', true);
        $sheet->getStyle('A1:'.self::letter(array_key_last(self::COLUMNS)).'1')->getFont()->setBold(true);

        foreach (array_keys(self::COLUMNS) as $key) {
            $sheet->getColumnDimension(self::letter($key))->setWidth(max(12, strlen(self::COLUMNS[$key]['heading']) + 4));
        }

        // Codes such as HSN 0401 keep their leading zero when typed in a text column.
        $sheet->getStyle(self::letter('hsn').':'.self::letter('hsn'))->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle(self::letter('sku').':'.self::letter('sku'))->getNumberFormat()->setFormatCode('@');
    }

    /**
     * The column letter of a column in the template, such as A for the product name.
     */
    private static function letter(string $key): string
    {
        $position = array_search($key, array_keys(self::COLUMNS), true);

        return chr(ord('A') + (int) $position);
    }

    /**
     * Which column a heading means, ignoring case, spacing, punctuation and the * of needed ones.
     */
    private static function keyFor(string $heading): ?string
    {
        $normal = self::normal($heading);

        foreach (self::COLUMNS as $key => $column) {
            if (in_array($normal, array_map(self::normal(...), [$column['heading'], ...$column['also']]), true)) {
                return $key;
            }
        }

        return null;
    }

    private static function normal(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($text)) ?? '';
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws ValidationException
     */
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
