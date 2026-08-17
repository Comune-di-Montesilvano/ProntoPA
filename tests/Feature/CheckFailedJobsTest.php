<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\JobFallitiNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckFailedJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nessuna_notifica_se_coda_pulita(): void
    {
        Notification::fake();

        $this->artisan('jobs:check-failed')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_notifica_admin_attivi_se_job_falliti(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['amministratore' => true, 'attivo' => true]);
        User::factory()->create(['amministratore' => true, 'attivo' => false]); // non attivo: no notifica
        User::factory()->create(['amministratore' => false]); // non admin: no notifica

        DB::table('failed_jobs')->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => '{}',
            'exception'  => "Connection refused\n#0 stack trace...",
            'failed_at'  => now(),
        ]);

        $this->artisan('jobs:check-failed')->assertExitCode(0);

        Notification::assertSentTo($admin, JobFallitiNotification::class, function ($n) {
            return $n->totale === 1;
        });
        Notification::assertCount(1);
    }
}
