<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('watchtower.connection') ?: parent::getConnection();
    }

    protected function table(): string
    {
        return config('watchtower.table_prefix', 'watchtower_').'job_records';
    }

    public function up(): void
    {
        $records = DB::connection($this->getConnection())->table($this->table());

        // Concurrent writers could store a job twice: keep the row that got furthest.
        $duplicates = (clone $records)->whereNotNull('uuid')->groupBy('uuid')->havingRaw('count(*) > 1')->pluck('uuid');

        foreach ($duplicates->chunk(500) as $uuids) {
            $keep = (clone $records)->whereIn('uuid', $uuids)->get(['id', 'uuid', 'finished_at'])
                ->groupBy('uuid')
                ->map(fn ($rows) => $rows->sortByDesc(fn ($row) => [$row->finished_at !== null, $row->id])->first()->id);

            (clone $records)->whereIn('uuid', $uuids)->whereNotIn('id', $keep->values())->delete();
        }

        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Like the create migration's dropIfExists: the configured connection may not hold the table.
        if (! $schema->hasTable($this->table())) {
            return;
        }

        $schema->table($this->table(), function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->index('uuid');
        });
    }
};
