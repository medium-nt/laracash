<?php

namespace App\Console\Commands;

use App\Models\Card;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CashbackImageGcCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cashback-image:gc {--hours=1 : Удалять файлы старше N часов}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удалить файлы-сироты скринов кешбэка (без связи с Card.cashback_image и старше N часов)';

    /**
     * Сборка мусора: файлы, загруженные через бота, но не привязанные к карте
     * (брошенная сессия / истёкший TTL state), удаляются по расписанию.
     */
    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dir = 'card_cashback_image';

        if (! $disk->exists($dir)) {
            $this->info('Папка скринов пуста.');

            return self::SUCCESS;
        }

        $hours = max(1, (int) $this->option('hours'));
        $threshold = now()->subHours($hours)->getTimestamp();

        // Файлы, на которые ссылаются карты пользователя
        $used = Card::query()
            ->whereNotNull('cashback_image')
            ->pluck('cashback_image')
            ->all();

        $deleted = 0;
        foreach ($disk->files($dir) as $file) {
            $basename = basename($file);

            if (in_array($basename, $used, true)) {
                continue;
            }

            if ($disk->lastModified($file) < $threshold) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Удалено файлов-сирот: {$deleted}.");

        return self::SUCCESS;
    }
}
