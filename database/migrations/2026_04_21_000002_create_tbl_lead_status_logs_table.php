<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_lead_status_logs')) {
            return;
        }

        Schema::create('tbl_lead_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->enum('status', [
                'new',
                'contacted',
                'in_progress',
                'qualified',
                'unqualified',
                'converted',
                'closed',
            ])->default('new');
            $table->text('notes')->nullable();
            $table->string('updated_by', 255)->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('tbl_leads')->onDelete('cascade');
            $table->index('lead_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_lead_status_logs');
    }
};
