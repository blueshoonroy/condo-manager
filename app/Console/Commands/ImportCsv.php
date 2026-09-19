<?php

namespace App\Console\Commands;

use App\Services\CsvImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCsv extends Command
{
    protected $signature = 'association:import {kind : bank or invoices} {file} {--apply : Commit after validation}';

    protected $description = 'Preview or import historical FreshBooks invoices or BMO transactions';

    public function handle(CsvImporter $importer): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['bank', 'invoices'], true)) {
            $this->error('Kind must be bank or invoices.');

            return self::FAILURE;
        }
        $path = realpath($this->argument('file'));
        if (! $path) {
            $this->error('File not found.');

            return self::FAILURE;
        }
        $result = $importer->inspect($path, $kind);
        $this->line(json_encode($result['summary'], JSON_PRETTY_PRINT));
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
        if ($result['errors']) {
            return self::FAILURE;
        }
        if ($this->option('apply')) {
            $id = DB::table('import_batches')->insertGetId(['kind' => $kind, 'filename' => basename($path), 'checksum' => hash_file('sha256', $path), 'status' => 'preview', 'created_at' => now(), 'updated_at' => now()]);
            $importer->commit($id, $path);
            $this->info('Import applied.');
        }

        return self::SUCCESS;
    }
}
