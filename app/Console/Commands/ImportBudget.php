<?php

namespace App\Console\Commands;

use App\Services\BudgetWorkbookImporter;
use Illuminate\Console\Command;

class ImportBudget extends Command
{
    protected $signature = 'association:import-budget {file} {--apply : Save the workbook after validation}';

    protected $description = 'Inspect or import an association budget workbook without changing invoices or bank transactions';

    public function handle(BudgetWorkbookImporter $importer): int
    {
        $path = realpath($this->argument('file'));
        if (! $path || ! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            $this->error('Provide a readable workbook smaller than 5 MB.');

            return self::FAILURE;
        }
        $sheets = $importer->inspect($path);
        $this->table(['Tab', 'Rows'], array_map(fn ($sheet) => [$sheet['name'], count($sheet['rows'])], $sheets));
        if ($this->option('apply')) {
            $id = $importer->import($path, basename($path));
            $this->info('Budget workbook #'.$id.' imported.');
        }

        return self::SUCCESS;
    }
}
