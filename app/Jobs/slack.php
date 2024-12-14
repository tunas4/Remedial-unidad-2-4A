<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class slack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $message;

    public function __construct($message)
    {
        $this->message = $message;
    }
    
    public function handle()
    {
        $token = env('SLACK_TOKEN');
        $channel = env('SLACK_CHANNEL');

        $response = Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $channel,
                'text' => $this->message
            ]);

        if ($response->failed()) 
        {
            Log::error('Error al enviar mensaje a Slack', [
                'response' => $response->body(),
            ]);
        }
    }

}
