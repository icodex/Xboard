<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\CommController;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\Guest\PlanController;
use App\Http\Controllers\V1\Guest\TelegramController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch'])->middleware('throttle:60,1');
            // Telegram
            $router->post('/telegram/webhook', [TelegramController::class, 'webhook'])->middleware('throttle:300,1');
            // Payment
            $router->match(['get', 'post'], '/payment/notify/{method}/{uuid}', [PaymentController::class, 'notify'])->middleware('throttle:300,1');
            // Comm
            $router->get('/comm/config', [CommController::class, 'config'])->middleware('throttle:60,1');
        });
    }
}
