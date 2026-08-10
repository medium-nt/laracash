<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class TelegramSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Настроить бот: установить команды нативного меню (кнопка «Меню» в Telegram)';

    /**
     * Команды нативного меню бота (видны в кнопке «Меню» клиента Telegram).
     */
    public function handle(TelegramBotService $bot): int
    {
        $commands = [
            ['command' => 'menu', 'description' => 'Главное меню'],
            ['command' => 'start', 'description' => 'Начать работу'],
        ];

        $bot->setMyCommands($commands);

        $this->info('Команды меню бота установлены: /menu, /start.');
        $this->comment('Кнопка «Меню» появится в клиенте Telegram (перезапусти чат бота, если не видна).');

        return self::SUCCESS;
    }
}
