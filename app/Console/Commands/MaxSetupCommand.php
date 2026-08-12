<?php

namespace App\Console\Commands;

use App\Services\Max\MaxBotService;
use Illuminate\Console\Command;

class MaxSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'max:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Настроить бот MAX: установить команды нативного меню';

    /**
     * Устанавливает команды нативного меню MAX-бота.
     *
     * @param  MaxBotService  $bot  Транспорт MAX
     * @return int Код завершения (0 - успех)
     */
    public function handle(MaxBotService $bot): int
    {
        $commands = [
            ['name' => '/menu', 'description' => 'Главное меню'],
        ];

        if (! $bot->setMyCommands($commands)) {
            $this->error('Не удалось установить команду меню MAX-бота (проверьте токен и сеть).');

            return self::FAILURE;
        }

        $this->info('Команда меню MAX-бота установлена: /menu.');
        $this->comment('Перезапусти диалог с ботом, если меню не появилось в клиенте MAX.');

        return self::SUCCESS;
    }
}
