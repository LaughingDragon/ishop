<?php

declare(strict_types=1);

namespace Drupal\ishop_items\ListingImport;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a listings spreadsheet (.xlsx or .csv) as it is (Phase 10).
 *
 * Each tab becomes a header row plus data rows. Values come back as the
 * spreadsheet holds them (numbers, dates, text); Values reads them.
 */
final class SheetReader {

  /**
   * More rows than this is not a listings sheet.
   */
  public const MAX_ROWS = 5000;

  /**
   * More columns than this are not read.
   */
  public const MAX_COLUMNS = 60;

  /**
   * File types accepted.
   */
  public const EXTENSIONS = ['xlsx', 'csv'];

  /**
   * Reads every visible tab.
   *
   * @param string $path
   *   A local file path.
   * @param string $extension
   *   Either 'xlsx' or 'csv' (the uploaded file's own name decides it).
   *
   * @return array<int, array{name: string, header_row: int, headers: array<int, string>, rows: array<int, array{row: int, cells: array<int, mixed>}>}>
   *   Tabs in order. A tab with no header row is left out.
   *
   * @throws \RuntimeException
   *   When the file can't be read (with a message for Cody).
   */
  public static function read(string $path, string $extension): array {
    $extension = strtolower($extension);
    if (!in_array($extension, self::EXTENSIONS, TRUE)) {
      throw new \RuntimeException('Only .xlsx (Excel) and .csv files can be read.');
    }
    if ($extension === 'csv') {
      $path = self::utf8Copy($path);
      $options = new CsvOptions();
      $options->FIELD_DELIMITER = self::delimiter($path);
      $reader = new CsvReader($options);
    }
    else {
      // Keep empty rows so row numbers match what Cody sees in Excel.
      $options = new XlsxOptions();
      $options->SHOULD_PRESERVE_EMPTY_ROWS = TRUE;
      $reader = new XlsxReader($options);
    }
    try {
      $reader->open($path);
      $sheets = [];
      foreach ($reader->getSheetIterator() as $sheet) {
        if (method_exists($sheet, 'isVisible') && !$sheet->isVisible()) {
          continue;
        }
        $raw = [];
        $number = 0;
        foreach ($sheet->getRowIterator() as $row) {
          $number++;
          if ($number > self::MAX_ROWS + 10) {
            throw new \RuntimeException(sprintf('The tab "%s" has more than %d rows. Is this the right file?', $sheet->getName(), self::MAX_ROWS));
          }
          $cells = array_slice($row->toArray(), 0, self::MAX_COLUMNS);
          $raw[$number] = $cells;
        }
        $tab = self::table($raw);
        if ($tab !== NULL) {
          $sheets[] = ['name' => $extension === 'csv' ? 'CSV' : $sheet->getName()] + $tab;
        }
      }
    }
    catch (\RuntimeException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('The file could not be read. Open it in Excel, save it again as .xlsx, and try once more.', 0, $e);
    }
    finally {
      $reader->close();
      if ($extension === 'csv' && str_contains($path, 'ishop-csv-')) {
        @unlink($path);
      }
    }
    if (!$sheets) {
      throw new \RuntimeException('No rows with column headings were found in the file.');
    }
    return $sheets;
  }

  /**
   * Finds the header row and the data rows under it.
   *
   * The header row is the first of the first ten rows with two or more
   * headings.
   *
   * @param array<int, array<int, mixed>> $raw
   *   Rows by sheet row number.
   */
  public static function table(array $raw): ?array {
    $header_row = NULL;
    foreach (array_slice($raw, 0, 10, TRUE) as $number => $cells) {
      $texts = array_filter($cells, fn ($c) => is_string($c) && trim($c) !== '');
      if (count($texts) >= 2) {
        $header_row = $number;
        break;
      }
    }
    if ($header_row === NULL) {
      return NULL;
    }
    $headers = [];
    foreach ($raw[$header_row] as $i => $cell) {
      $headers[$i] = Values::text($cell);
    }
    // Columns with no heading but with data still count (as "Column C").
    $rows = [];
    foreach ($raw as $number => $cells) {
      if ($number <= $header_row) {
        continue;
      }
      foreach ($cells as $i => $cell) {
        if (!isset($headers[$i]) && Values::text($cell) !== '') {
          $headers[$i] = '';
        }
      }
      $rows[] = ['row' => (int) $number, 'cells' => $cells];
    }
    ksort($headers);
    $headers = array_replace(array_fill(0, $headers ? max(array_keys($headers)) + 1 : 0, ''), $headers);
    foreach ($headers as $i => $header) {
      if ($header === '') {
        $headers[$i] = 'Column ' . self::letter($i);
      }
    }
    return ['header_row' => $header_row, 'headers' => $headers, 'rows' => $rows];
  }

  /**
   * A column letter: 0 => A, 26 => AA.
   */
  public static function letter(int $index): string {
    $letter = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
      $letter = chr(65 + ($n - 1) % 26) . $letter;
    }
    return $letter;
  }

  /**
   * The CSV's delimiter: comma, semicolon or tab, from the first line.
   */
  private static function delimiter(string $path): string {
    $line = (string) fgets(fopen($path, 'r') ?: throw new \RuntimeException('The file could not be opened.'));
    $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
    arsort($counts);
    return (string) array_key_first($counts);
  }

  /**
   * A UTF-8 copy of a CSV (Excel on Windows saves Windows-1252).
   */
  private static function utf8Copy(string $path): string {
    $data = file_get_contents($path);
    if ($data === FALSE) {
      throw new \RuntimeException('The file could not be opened.');
    }
    $data = preg_replace('/^\xEF\xBB\xBF/', '', $data) ?? $data;
    if (!mb_check_encoding($data, 'UTF-8')) {
      $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
    }
    $copy = tempnam(sys_get_temp_dir(), 'ishop-csv-');
    file_put_contents($copy, $data);
    return $copy;
  }

}
