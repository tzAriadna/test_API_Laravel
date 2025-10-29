<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

/**
 * Команда для загрузки данных из внешнего API и сохранения их в базу данных.
 *
 * Получает данные по четырем сущностям: incomes, sales, orders, stocks.
 * Поддерживает пагинацию и параметр --limit для контроля количества записей.
 */
class FetchWbData extends Command
{

    protected $signature = 'fetch:wb-data 
                            {--dateFrom=} 
                            {--dateTo=}
                            {--limit=500}';

    protected $description = 'Fetch sales, orders, stocks, incomes from WB API and store in DB';

    /**
     * Секретный токен API
     */
    private $apiKey = 'E6kUTYrYwZq2tN4QEtyzsbEBk3ie';

    /**
     * Хост API (сервер, с которого загружаются данные)
     */
    private $apiHost = 'http://109.73.206.144:6969/api';

    public function handle()
    {
        $dateFrom = $this->option('dateFrom') ?? date('Y-m-01'); // по умолчанию — начало текущего месяца
        $dateTo   = $this->option('dateTo') ?? date('Y-m-d');     // по умолчанию — текущая дата
        $limit    = $this->option('limit') ?? 500;                 // лимит записей на страницу

        $this->info("Fetching data from $dateFrom to $dateTo with limit $limit...");

        // Поочередно загружаем данные для каждой сущности
        $this->fetchAndSave('incomes', $dateFrom, $dateTo, 'incomes', $limit);
        $this->fetchAndSave('sales', $dateFrom, $dateTo, 'sales', $limit);
        $this->fetchAndSave('orders', $dateFrom, $dateTo, 'orders', $limit);
        $this->fetchAndSave('stocks', date('Y-m-d'), date('Y-m-d'), 'stocks', $limit);

        $this->info('All data fetched and saved successfully.');
    }

    /**
     * Загружает данные по конкретной сущности (endpoint) и сохраняет в БД.
     *
     * @param string $endpoint — эндпоинт API (например, "sales")
     * @param string $dateFrom — начальная дата
     * @param string $dateTo — конечная дата
     * @param string $table — название таблицы в БД
     * @param int $limit — количество записей на страницу
     */
    private function fetchAndSave($endpoint, $dateFrom, $dateTo, $table, $limit)
    {
        $page = 1; // начинаем с первой страницы

        do {
            // Отправляем GET-запрос к API с параметрами
            $response = Http::get("{$this->apiHost}/{$endpoint}", [
                'key' => $this->apiKey,
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
                'page' => $page,
                'limit' => $limit
            ]);

            // Проверяем, успешно ли выполнен запрос
            if (!$response->ok()) {
                $this->error("Error fetching {$endpoint}, page {$page}");
                break;
            }

            // Преобразуем ответ в массив
            $data = $response->json();
            $items = $data['data'] ?? [];

            // Если данных нет — выходим из цикла
            if (empty($items)) {
                $this->warn("No data found for {$endpoint}, page {$page}");
                break;
            }

            // Проходим по каждому элементу и сохраняем в таблицу
            foreach ($items as $item) {

                // Формируем уникальный ключ для каждой сущности
                $unique = match($table) {
                    'incomes' => [
                        'income_id' => $item['income_id'] ?? null,
                        'supplier_article' => $item['supplier_article'] ?? null,
                        'tech_size' => $item['tech_size'] ?? null
                    ],
                    'sales' => [
                        'sale_id' => $item['sale_id'] ?? null
                    ],
                    'orders' => [
                        'g_number' => $item['g_number'] ?? null,
                        'supplier_article' => $item['supplier_article'] ?? null,
                        'tech_size' => $item['tech_size'] ?? null
                    ],
                    'stocks' => [
                        'nm_id' => $item['nm_id'] ?? null,
                        'warehouse_name' => $item['warehouse_name'] ?? null,
                        'tech_size' => $item['tech_size'] ?? null
                    ],
                    default => []
                };

                // Вставляем или обновляем запись (updateOrInsert)
                DB::table($table)->updateOrInsert($unique, $item);
            }

            // Выводим информацию о количестве сохраненных строк
            $this->info("Saved " . count($items) . " records from {$endpoint}, page {$page}");

            // Переходим на следующую страницу
            $page++;
        } while (!empty($items)); // продолжаем пока API возвращает данные
    }
}
