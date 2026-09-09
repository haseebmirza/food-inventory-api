<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sanctum:prune', function () {
    $deleted = PersonalAccessToken::where('expires_at', '<', now())->delete();
    $this->info("Pruned {$deleted} expired token(s).");
})->purpose('Delete expired Sanctum tokens');

Schedule::command('sanctum:prune')->daily()->at('02:00');
