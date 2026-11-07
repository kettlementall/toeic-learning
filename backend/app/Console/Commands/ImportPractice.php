<?php

namespace App\Console\Commands;

use App\Services\PracticeImporter;
use Illuminate\Console\Command;

class ImportPractice extends Command
{
    protected $signature = 'practice:import
                            {dir? : Directory of set files (default: config practice.root)}
                            {--only= : Import a single set code}
                            {--dry-run : Validate only, write nothing}';

    protected $description = 'Load real-test practice sets (JSON + media) into the practice bank';

    public function handle(PracticeImporter $importer): int
    {
        $dir = rtrim($this->argument('dir') ?? config('practice.root'), '/');
        $files = glob($dir . '/*.json') ?: [];
        if (! $files) {
            $this->error("No set files in {$dir}");
            return self::FAILURE;
        }

        // validate everything first: a half-imported bank is worse than none
        $sets = [];
        $errors = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data)) {
                $errors[] = basename($file) . ': not valid JSON';
                continue;
            }
            if ($this->option('only') && ($data['code'] ?? null) !== $this->option('only')) {
                continue;
            }
            $errors = array_merge($errors, $importer->validate($data));
            $sets[] = $data;
        }

        $codes = array_count_values(array_map(fn ($s) => $s['code'] ?? '', $sets));
        foreach ($codes as $code => $n) {
            if ($n > 1) {
                $errors[] = "set code {$code} appears in {$n} files";
            }
        }

        if ($errors) {
            foreach ($errors as $e) {
                $this->error($e);
            }
            $this->error(count($errors) . ' problem(s); nothing imported.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(count($sets) . ' set(s) valid; dry run, nothing written.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($sets as $data) {
            $r = $importer->import($data);
            $rows[] = [$data['code'], $r['questions'], $r['groups'], $r['dictations'], $r['removed'], $r['kept']];
        }
        $this->table(['set', 'questions', 'groups', 'dictations', 'removed', 'kept (answered)'], $rows);

        return self::SUCCESS;
    }
}
