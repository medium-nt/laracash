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
     * Устанавливает команды нативного меню TG-бота.
     *
     * @param  TelegramBotService  $bot  Транспорт Telegram
     * @return int Код завершения (0 - успех)
     */
    public function handle(TelegramBotService $bot): int
    {
        $commands = [
            ['command' => 'menu', 'description' => 'Главное меню'],
        ];

        if (! $bot->setMyCommands($commands)) {
            $this->error('Не удалось установить команду меню бота (проверьте токен и сеть).');

            return self::FAILURE;
        }

        $this->info('Команда меню бота установлена: /menu.');
        $this->comment('Кнопка «Меню» появится в клиенте Telegram (перезапусти чат бота, если не видна).');

        return self::SUCCESS;
    }
}
