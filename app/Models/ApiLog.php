<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $table      = 'api_logs';
    public    $timestamps = false;

    protected $fillable = [
        'direction', 'endpoint', 'http_status', 'payload', 'response', 'segnalazione_id',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'response'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function logInbound(string $endpoint, array $payload, int $status, mixed $response, ?int $segnalazioneId = null): void
    {
        static::create([
            'direction'       => 'inbound',
            'endpoint'        => $endpoint,
            'http_status'     => $status,
            'payload'         => $payload,
            'response'        => is_array($response) ? $response : ['body' => $response],
            'segnalazione_id' => $segnalazioneId,
        ]);
    }

    public static function logOutbound(string $endpoint, array $payload, int $status, mixed $response, ?int $segnalazioneId = null): void
    {
        static::create([
            'direction'       => 'outbound',
            'endpoint'        => $endpoint,
            'http_status'     => $status,
            'payload'         => $payload,
            'response'        => is_array($response) ? $response : ['body' => $response],
            'segnalazione_id' => $segnalazioneId,
        ]);
    }
}
