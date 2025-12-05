<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->json('payload'); // Raw JSON from the request
            $table->json('summary'); // { created, updated, skipped, missing: [...] }
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sync_logs');
    }
};
