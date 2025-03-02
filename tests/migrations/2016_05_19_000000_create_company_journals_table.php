<?php

declare(strict_types = 1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyJournalsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * NOTE: This is only used for testing purposes.  A Company Journals table is not needed, and is simply a way to create some objects that have journals attached to them for accounting purposes.  This is best illustrated by studying the tests; but the most important thing to rememeber is that this is entirely optional and only one way of adding journals to "meanningless" objects (Company Journals), or you could, if you wanted, add functions to a company hournal model.
     */
    public function up(): void
    {
        Schema::create('company_journals', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_journals');
    }
}
