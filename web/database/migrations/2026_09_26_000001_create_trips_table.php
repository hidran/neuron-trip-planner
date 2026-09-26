<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The application's own view of each trip.
 *
 * The workflow keeps its durable state in workflow_store, which is not meant
 * to be queried. This table is the projection the API and the UI read:
 * status, the question the traveller has to answer, and a summary.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('ask');
            $table->string('status')->index();            // working | waiting | finished | failed
            $table->string('phase')->nullable();          // what the agents are doing while working
            $table->json('pending')->nullable();          // the open question, when waiting
            $table->json('summary')->nullable();          // request, dates, window, selection, bookings, feedback
            $table->string('outcome')->nullable();
            $table->text('note')->nullable();
            $table->text('error')->nullable();
            // The fences a resume must present (Chapter 22): the run and the
            // execution attempt that paused.
            $table->string('run_id')->nullable();
            $table->unsignedInteger('execution_attempt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
