<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'remind_ticket')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->tinyInteger('remind_ticket')->nullable()->default(1)->after('remind_traffic');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'remind_ticket')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('remind_ticket');
            });
        }
    }
};
