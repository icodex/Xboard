<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected ?object $msg = null;
    protected TelegramService $telegramService;
    protected UserService $userService;

    public function __construct(TelegramService $telegramService, UserService $userService)
    {
        $this->telegramService = $telegramService;
        $this->userService = $userService;
    }

    public function webhook(Request $request): void
    {
        $token = (string) admin_setting('telegram_bot_token', '');
        if ($token === '') {
            throw new ApiException('telegram bot is not configured', 403);
        }

        // 用 Telegram 官方的 secret_token 头校验。旧的 query 里 md5(bot token) 会出现在
        // 任何访问日志/监控系统里，读到日志就能冒充任意已绑定用户。
        $secret = (string) admin_setting('telegram_webhook_secret', '');
        if ($secret === '') {
            throw new ApiException('telegram webhook secret is not configured; re-register the webhook', 403);
        }

        if (!hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            throw new ApiException('invalid telegram secret token', 401);
        }

        $data = $request->json()->all();

        $this->formatMessage($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    private function handle(): void
    {
        if (!$this->msg)
            return;
        $msg = $this->msg;
        $this->processBotName($msg);
        try {
            HookManager::call('telegram.message.before', [$msg]);
            $handled = HookManager::filter('telegram.message.handle', false, [$msg]);
            if (!$handled) {
                HookManager::call('telegram.message.unhandled', [$msg]);
            }
            HookManager::call('telegram.message.after', [$msg]);
        } catch (\Exception $e) {
            HookManager::call('telegram.message.error', [$msg, $e]);
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    private function processBotName(object $msg): void
    {
        $commandParts = explode('@', $msg->command);

        if (count($commandParts) === 2) {
            $botName = $this->getBotName();
            if ($commandParts[1] === $botName) {
                $msg->command = $commandParts[0];
            }
        }
    }

    private function getBotName(): string
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data): void
    {
        if (!isset($data['message']['text']))
            return;

        $message = $data['message'];
        $text = explode(' ', $message['text']);

        $this->msg = (object) [
            'command' => $text[0],
            'args' => array_slice($text, 1),
            'chat_id' => $message['chat']['id'],
            'message_id' => $message['message_id'],
            'message_type' => 'message',
            'text' => $message['text'],
            'is_private' => $message['chat']['type'] === 'private',
        ];

        if (isset($message['reply_to_message']['text'])) {
            $this->msg->message_type = 'reply_message';
            $this->msg->reply_text = $message['reply_to_message']['text'];
        }
    }

    private function formatChatJoinRequest(array $data): void
    {
        $joinRequest = $data['chat_join_request'] ?? null;
        if (!$joinRequest)
            return;

        $chatId = $joinRequest['chat']['id'] ?? null;
        $userId = $joinRequest['from']['id'] ?? null;

        if (!$chatId || !$userId)
            return;

        $user = User::where('telegram_id', $userId)->first();

        if (!$user) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        $this->telegramService->approveChatJoinRequest($chatId, $userId);
    }
}
