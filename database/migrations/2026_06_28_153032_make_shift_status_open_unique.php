<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->isMysql()) {
            $this->upMysql();

            return;
        }

        $this->upSqliteOrPostgres();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->isMysql()) {
            $this->downMysql();

            return;
        }

        $this->downSqliteOrPostgres();
    }

    /**
     * MySQL/MariaDB do not support partial indexes. Use a generated column
     * with a unique index so that only one row may have status = 'open'.
     */
    private function upMysql(): void
    {
        // The composite index is used by the foreign key, so the foreign key
        // must be dropped before the index can be removed.
        DB::statement('ALTER TABLE shifts DROP FOREIGN KEY shifts_user_id_foreign');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id)');

        DB::statement("ALTER TABLE shifts ADD COLUMN open_status TINYINT UNSIGNED AS (CASE WHEN status = 'open' THEN 1 ELSE NULL END) STORED");
        DB::statement('CREATE UNIQUE INDEX shifts_status_open_unique ON shifts (open_status)');
    }

    private function downMysql(): void
    {
        DB::statement('DROP INDEX shifts_status_open_unique ON shifts');
        DB::statement('ALTER TABLE shifts DROP COLUMN open_status');

        DB::statement('ALTER TABLE shifts DROP FOREIGN KEY shifts_user_id_foreign');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->index(['user_id', 'status']);
        });

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id)');
    }

    /**
     * SQLite and PostgreSQL support partial indexes natively.
     */
    private function upSqliteOrPostgres(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX shifts_status_open_unique ON shifts (status) WHERE status = 'open'");
    }

    private function downSqliteOrPostgres(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_status_open_unique');

        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
