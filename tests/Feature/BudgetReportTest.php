<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BudgetReport;
use App\Services\BudgetWorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class BudgetReportTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    private function workbook(bool $invalidXml = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'budget');
        $this->files[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Budget 2025" r:id="rId1"/><sheet name="BMO 2025 Transactions" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>ComEd</t></si></sst>');
        $rows = [
            1 => ['A' => 'Budget 2025 EST', 'B' => 'Jan', 'N' => 'Total'],
            2 => ['A' => ['shared' => 0], 'B' => 10.12345],
            3 => ['A' => 'Total', 'B' => 10.12345, 'N' => ['formula' => 'SUM(B3:M3)', 'value' => 10.12345]],
            4 => ['A' => 'Est Income'],
            5 => ['A' => 'Unit 1', 'B' => 100],
            6 => ['B' => 100, 'N' => 100],
            7 => ['A' => 'Actual 2024'],
            8 => ['A' => 'ComEd', 'B' => 20, 'C' => 0],
            9 => ['A' => 'Total Expenses', 'B' => 20, 'N' => 20],
            10 => ['A' => 'Special Assessments / Inc', 'B' => 5],
            11 => ['A' => 'Surplus/Deficit', 'B' => 85, 'N' => 999],
            12 => ['A' => 'Income'],
            13 => ['A' => 'Unit 1', 'B' => 100, 'C' => 'pre-paid'],
            14 => ['A' => 'Total', 'B' => 100, 'N' => 100],
            16 => ['A' => 'Notes:', 'B' => '<script>alert(1)</script>'],
            17 => ['A' => 'Checking', 'B' => 200],
        ];
        $xml = $this->sheetXml($rows);
        if ($invalidXml) {
            $xml = '<!DOCTYPE worksheet [<!ENTITY external SYSTEM "file:///etc/passwd">]>'.$xml;
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheetXml([1 => ['A' => 'POSTED DATE', 'B' => 'DESCRIPTION', 'C' => 'AMOUNT'], 2 => ['A' => 45659, 'B' => 'Utility bill', 'C' => -20]]));
        $zip->close();

        return $path;
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $number => $cells) {
            $xml .= '<row r="'.$number.'">';
            foreach ($cells as $column => $value) {
                $coordinate = $column.$number;
                if (is_array($value)) {
                    $xml .= isset($value['shared']) ? '<c r="'.$coordinate.'" t="s"><v>'.$value['shared'].'</v></c>' : '<c r="'.$coordinate.'"><f>'.$value['formula'].'</f><v>'.$value['value'].'</v></c>';
                } elseif (is_numeric($value)) {
                    $xml .= '<c r="'.$coordinate.'"><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$coordinate.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    public function test_import_preserves_figures_formulas_blanks_and_bank_records_without_ledger_changes(): void
    {
        $path = $this->workbook();
        $importer = app(BudgetWorkbookImporter::class);
        $id = $importer->import($path, 'Budget.xlsx');
        $this->assertSame($id, $importer->import($path, 'Budget.xlsx'));
        $this->assertDatabaseCount('budget_workbooks', 1);
        $this->assertDatabaseCount('bank_transactions', 0);
        $this->assertDatabaseCount('invoices', 0);
        $record = DB::table('budget_workbooks')->find($id);
        $this->assertSame(file_get_contents($path), base64_decode($record->original_base64));
        $sheets = json_decode($record->sheets, true);
        $this->assertSame('ComEd', $sheets[0]['rows'][1]['cells']['A']['value']);
        $this->assertSame('10.12345', $sheets[0]['rows'][1]['cells']['B']['value']);
        $this->assertArrayNotHasKey('C', $sheets[0]['rows'][1]['cells']);
        $this->assertSame('SUM(B3:M3)', $sheets[0]['rows'][2]['cells']['N']['formula']);
        $this->assertSame('2025-01-02', $sheets[1]['rows'][1]['cells']['A']['value']);
        $report = app(BudgetReport::class)->build($sheets[0]);
        $this->assertSame(105.0, $report['summary']['actual_income']);
        $this->assertSame(85.0, $report['summary']['actual_net']);
        $this->assertCount(3, $report['warnings']);
        $this->assertSame('999', $report['sections'][2]['rows'][3]['cells']['N']['value']);
    }

    public function test_report_download_and_year_selection_require_resident_access_and_escape_notes(): void
    {
        $id = app(BudgetWorkbookImporter::class)->import($this->workbook(), 'Budget.xlsx');
        $this->get('/budget')->assertRedirect('/login');
        $this->get('/budget/workbooks/'.$id)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/budget')->assertOk()->assertSee('2025 annual report')->assertSee('$10.12')->assertSee('pre-paid')->assertSee('Supporting bank records')->assertSee('Actual expenses &amp; cash flow', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/budget?year=2020')->assertNotFound();
        $this->get('/budget?workbook=99999')->assertNotFound();
        $this->get('/budget/workbooks/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_upload_is_admin_only_and_read_only_preview_cannot_import(): void
    {
        $path = $this->workbook();
        $file = new UploadedFile($path, 'Budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $resident = User::factory()->create();
        $this->actingAs($resident)->post('/admin/budget/import', ['workbook' => $file])->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/budget/import', ['workbook' => $file])->assertSessionHasNoErrors()->assertRedirect('/budget?workbook=1');
        $this->post('/admin/impersonate/'.$resident->id);
        $this->post('/admin/budget/import', ['workbook' => $file])->assertForbidden();
        $this->assertDatabaseCount('budget_workbooks', 1);
    }

    public function test_external_entities_are_rejected_before_any_data_is_saved(): void
    {
        $this->expectException(ValidationException::class);
        app(BudgetWorkbookImporter::class)->import($this->workbook(true), 'Budget.xlsx');
    }

    public function test_uncalculated_formulas_are_not_silently_imported_as_zero(): void
    {
        $path = $this->workbook();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<f>SUM(B3:M3)</f><v>10.12345</v>', '<f>SUM(B3:M3)</f>', $xml));
        $zip->close();
        try {
            app(BudgetWorkbookImporter::class)->import($path, 'Budget.xlsx');
            $this->fail('An uncalculated total must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('uncalculated formula', $exception->errors()['workbook'][0]);
        }
        $this->assertDatabaseCount('budget_workbooks', 0);
    }
}
