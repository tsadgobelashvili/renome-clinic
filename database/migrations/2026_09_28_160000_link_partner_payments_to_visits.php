<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        $view = $this->detachView();
        Schema::table('partner_patient_payments', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $table->softDeletes();
        });
        if ($view) { DB::statement($view); }
    }
    public function down(): void {
        $view = $this->detachView();
        Schema::table('partner_patient_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_id'); $table->dropSoftDeletes();
        });
        if ($view) { DB::statement($view); }
    }
    private function detachView(): ?string {
        if (DB::getDriverName() !== 'sqlite') { return null; }
        $sql = DB::table('sqlite_master')->where('type', 'view')->where('name', 'partner_finance_entries')->value('sql');
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');
        return $sql;
    }
};
