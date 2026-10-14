<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform settings an operator can change without a deploy.
 *
 * The thresholds that decide when the admin dashboard calls something a
 * problem were private consts in IssueDetector: a backlog of 100 is a
 * warning, 500 is critical, 50 failed items is critical, 10 rejections in a
 * day is a warning. Changing any of them meant editing PHP, raising a pull
 * request and shipping - for a number whose right value is only learned by
 * watching the platform run. The certificate notification schedule had the
 * same problem one level removed: notify_at_days was a literal in config and
 * the channels came from an environment variable, so both needed a redeploy.
 *
 * Key and value rather than a column per setting, because the alternative is
 * a migration every time a threshold is added and these are operational
 * knobs, not domain data. The definitions - label, type, bounds, default -
 * live in the Settings service, which is the thing that has to be right; a
 * row here only overrides a default.
 *
 * Platform-wide, not per organization. A backlog is the platform's backlog.
 *
 * updated_by holds an email rather than an id, for the same reason the kill
 * switches do: this is read by a person asking who changed a threshold, and a
 * UUID does not answer that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 64)->primary();

            // JSON, so a list - notify_at_days, channels - round-trips
            // without a separate encoding convention per setting.
            $table->text('value');

            $table->string('updated_by', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
