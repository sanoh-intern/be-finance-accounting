<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inv_header', function (Blueprint $table) {
            $table->string('inv_no', 255)->primary();

            // Foreign key to user
            $table->string('bp_code')->nullable();
            $table->foreign('bp_code')->references('bp_code')->on('user');

            $table->date('inv_date')->nullable();
            $table->string('inv_faktur')->nullable();
            $table->string('inv_supplier')->nullable();
            $table->integer('total_dpp')->nullable();

            // Relation PPN
            $table->unsignedBigInteger('ppn_id')->nullable();
            $table->foreign('ppn_id')->references('ppn_id')->on('inv_ppn');
            $table->integer('tax_base_amount')->nullable();
            $table->integer('tax_amount')->nullable();

            // Relation PPH
            $table->unsignedBigInteger('pph_id')->nullable();
            $table->foreign('pph_id')->references('pph_id')->on('inv_pph');
            $table->integer('pph_base_amount')->nullable();
            $table->integer('pph_amount')->nullable();

            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();

            $table->integer('total_amount')->nullable();
            $table->string('status')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_header');
    }
};
