<?php

namespace App\Services;

use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class BudgetWorkbookImporter
{
    public function inspect(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('Please upload an Excel .xlsx workbook.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 2000) {
                $this->invalid('This workbook contains too many files.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $size += $zip->statIndex($index)['size'];
            }
            if ($size > 25 * 1024 * 1024) {
                $this->invalid('The expanded workbook must be smaller than 25 MB.');
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relationships = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($relationships->children() as $relationship) {
                $target = (string) $relationship['Target'];
                if ((string) $relationship['TargetMode'] !== 'External' && ! str_contains($target, '..')) {
                    $targets[(string) $relationship['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($zip, 'xl/sharedStrings.xml');
                foreach ($shared->xpath('//m:si') as $item) {
                    $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $strings[] = implode('', array_map(strval(...), $item->xpath('.//m:t')));
                }
            }
            $sheets = [];
            foreach ($workbook->xpath('//m:sheet') as $sheet) {
                $name = (string) $sheet['name'];
                if (! preg_match('/^(Budget|BMO) (20\d{2})(?: Transactions)?$/', $name, $match)) {
                    $this->invalid('Unsupported tab “'.$name.'”. Use the association budget workbook layout.');
                }
                $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $xml = $this->xml($zip, $targets[$relationId] ?? '');
                $rows = [];
                foreach ($xml->xpath('//m:sheetData/m:row') as $row) {
                    $cells = [];
                    foreach ($row->c as $cell) {
                        $reference = (string) $cell['r'];
                        if (! preg_match('/^([A-Z]{1,2})([1-9]\d*)$/', $reference, $coordinate) || (int) $coordinate[2] > 5000) {
                            $this->invalid('The workbook has unsupported cell coordinates.');
                        }
                        $type = (string) $cell['t'];
                        $value = (string) $cell->v;
                        if ($type === 'e' || (isset($cell->f) && $value === '')) {
                            $this->invalid('Cell '.$name.'!'.$reference.' has an error or an uncalculated formula. Recalculate and save the workbook in Excel or Google Sheets, then upload it again.');
                        }
                        if ($type === 's') {
                            $value = $strings[(int) $value] ?? '';
                        } elseif ($type === 'inlineStr') {
                            $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                            $value = implode('', array_map(strval(...), $cell->xpath('.//m:t')));
                        }
                        if ($value === '' && ! isset($cell->f)) {
                            continue;
                        }
                        $numeric = ($type === '' || $type === 'n') && is_numeric($value);
                        if ($match[1] === 'BMO' && $coordinate[1] === 'A' && $numeric) {
                            $epoch = (string) ($workbook->workbookPr['date1904'] ?? '') === '1' ? '1904-01-01' : '1899-12-30';
                            $value = CarbonImmutable::parse($epoch)->addDays((int) $value)->toDateString();
                            $numeric = false;
                        }
                        $cells[$coordinate[1]] = ['value' => $value, 'numeric' => $numeric, 'formula' => isset($cell->f) ? (string) $cell->f : null];
                    }
                    if ($cells) {
                        $rows[] = ['number' => (int) $row['r'], 'cells' => $cells];
                    }
                }
                $sheets[] = ['name' => $name, 'year' => (int) $match[2], 'kind' => $match[1] === 'Budget' ? 'budget' : 'transactions', 'rows' => $rows];
            }
            if (! collect($sheets)->contains('kind', 'budget')) {
                $this->invalid('No budget tabs were found.');
            }
            foreach (collect($sheets)->where('kind', 'budget') as $sheet) {
                app(BudgetReport::class)->build($sheet);
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    public function import(string $path, string $filename, ?int $actor = null): int
    {
        $sheets = $this->inspect($path);
        $checksum = hash_file('sha256', $path);

        return DB::transaction(function () use ($path, $filename, $actor, $sheets, $checksum) {
            DB::table('budget_workbooks')->insertOrIgnore([
                'filename' => mb_substr(basename($filename), 0, 255), 'checksum' => $checksum,
                'sheets' => json_encode($sheets, JSON_THROW_ON_ERROR), 'original_base64' => base64_encode(file_get_contents($path)),
                'imported_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = (int) DB::table('budget_workbooks')->where('checksum', $checksum)->value('id');
            Audit::record('budget.imported', 'workbook:'.$id, ['sheets' => count($sheets)], $actor);

            return $id;
        });
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $content = $zip->getFromName($path);
        if ($content === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            $this->invalid('The workbook contains missing or unsupported XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $this->invalid('The workbook contains invalid XML.');
            }
            $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['workbook' => $message]);
    }
}
