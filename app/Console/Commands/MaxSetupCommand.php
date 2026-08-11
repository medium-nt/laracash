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
            ['command' => 'menu', 'description' => 'Главное меню'],
            ['command' => 'start', 'description' => 'Начать работу'],
        ];

        $bot->setMyCommands($commands);

        $this->info('Команды меню MAX-бота установлены: /menu, /start.');

        return self::SUCCESS;
    }
}
