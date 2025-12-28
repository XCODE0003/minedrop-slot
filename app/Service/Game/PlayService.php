<?php

namespace App\Service\Game;

use App\Models\Bet;
use App\Models\SessionGame;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlayService
{
    private SessionGame $session;
    private $bet;

    private $blockHP = [
        'd' => 1, // земля
        'c' => 2, // камень
        'r' => 4, // руда
        'g' => 5, // золото
        'm' => 6, // алмаз
        'o' => 7, // обсидиан
    ];

    // payout по блокам — минимум, который уже подтверждается твоими "правильными" JSON
    // (в этом кейсе: c -> 0.2 обязателен, иначе reel0 не совпадёт)
    private $blockPayout = [
        'c' => 0.1,
        'r' => 1,
        'g' => 3,
        'm' => 5,
        'o' => 25,
        // d,o по умолчанию 0.0
    ];

    private $pickaxeHits = [
        '5' => 1, // деревянная
        '4' => 2, // железная
        '3' => 3, // золотая
        '2' => 5, // алмазная
        's' => 1, // special (1 удар)
    ];

    /**
     * Множитель для детерминированной генерации board и blocks
     */
    private $multiplierSeed = null;

    public function __construct(SessionGame $session, $bet)
    {
        $this->session = $session;
        $this->bet = $bet;
    }

    /**
     * Задать множитель, который будет использоваться как seed для генерации.
     * Передайте нужный multiplier перед вызовом play().
     */
    public function setMultiplierSeed($multiplier): void
    {
        $this->multiplierSeed = $multiplier;
    }
    public function play(): array
    {
        $round = $this->getRound();

        return $round;
    }

    public function playBonus(): array
    {
        $seed = $this->generateSeed();
        $state = [];
        $stateIndex = 0;

        $targetMultiplier = null;
        if ($this->multiplierSeed !== null) {
            $targetMultiplier = (float) $this->multiplierSeed;
        }

        // 1. Начальное состояние с seed
        $state[] = [
            'index' => $stateIndex++,
            's' => $seed,
            'type' => 's',
        ];


        $initialBoard = $this->generateBonusBoard();

        // Находим позиции бонусных символов (вероятно 's')
        $bonusPositions = [];
        $boardRows = str_split($initialBoard, 3);
        foreach ($boardRows as $reel => $row) {
            $rowSymbols = str_split($row);
            foreach ($rowSymbols as $rowIndex => $symbol) {
                if ($symbol === 's') {
                    $bonusPositions[] = [$reel, $rowIndex];
                }
            }
        }

        $anticipation = count($bonusPositions);
        $freeSpinCount = 5;

        // Если задан множитель, перебираем варианты до получения нужного выигрыша
        $maxAttempts = $targetMultiplier !== null ? 1000000 : 1;
        $attempt = 0;
        $totalWin = 0.0;
        $allSpinsData = [];
        $finalBlocks = null;

        while ($attempt < $maxAttempts) {
            $totalWin = 0.0;
            $allSpinsData = [];

            // Генерируем ОДНУ карту блоков на все 5 раундов
            $blocks = $this->generateBlocks($targetMultiplier);

            // Состояние для всех раундов: сломанные блоки и HP блоков
            $alreadyBroken = [];  // Массив индексов уже сломанных блоков
            $blockHPState = [];   // Состояние HP блоков между раундами

            // 5 раундов на одной карте блоков
            for ($spin = 0; $spin < $freeSpinCount; $spin++) {
                // Генерируем новые кирки (board) для каждого раунда
                // Первый раунд использует initialBoard с бонусными символами
                // Остальные раунды - кирки распределяются равномерно по оставшимся раундам
                if ($spin === 0) {
                    $currentBoard = $initialBoard;
                } else {
                    $currentBoard = $this->generateBonusBoardForRound($alreadyBroken, $spin, $freeSpinCount, $targetMultiplier);
                }

                // Используем bonus-версии функций с передачей состояния
                $pickaxePhase = $this->bonusMine($blocks, $currentBoard, $alreadyBroken, $blockHPState);
                $tntPhase = $this->bonusTnt($blocks, $currentBoard, $alreadyBroken, $blockHPState);
                $spinWin = $this->calculateWin($pickaxePhase, $tntPhase);
                $totalWin += $spinWin;

                $allSpinsData[] = [
                    'board' => $currentBoard,
                    'blocks' => $blocks,
                    'pickaxePhase' => $pickaxePhase,
                    'tntPhase' => $tntPhase,
                    'spinWin' => $spinWin,
                    'alreadyBroken' => $alreadyBroken, // Сохраняем состояние для отладки
                ];
            }

            // Для бонуски - выходим после первой попытки, множители добьём через сундуки
            if ($targetMultiplier === null) {
                break;
            }

            // Если выигрыш достаточен - выходим
            if ($totalWin >= $targetMultiplier || abs($totalWin - $targetMultiplier) < 0.01) {
                break;
            }

            $attempt++;
        }

        // Рассчитываем множители из сундуков если выигрыш от блоков недостаточен
        $multipliers = [];
        $baseWinAmount = $totalWin;
        $finalWinAmount = $totalWin;

        if ($targetMultiplier !== null && $totalWin > 0 && $totalWin < $targetMultiplier) {
            // Нужный общий множитель от сундуков
            $requiredMultiplier = $targetMultiplier / $totalWin;

            // Генерируем массив множителей (сундуков)
            $multipliers = $this->generateChestMultipliers($requiredMultiplier);

            // Итоговый выигрыш = baseWin * сумма множителей
            $multiplierSum = array_sum($multipliers);
            $finalWinAmount = $baseWinAmount * $multiplierSum;
        } elseif ($targetMultiplier !== null && $totalWin == 0) {
            // Если выигрыш 0, но нужен множитель - даём минимальный выигрыш
            $baseWinAmount = 0.1;
            $multipliers = $this->generateChestMultipliers($targetMultiplier / $baseWinAmount);
            $finalWinAmount = $baseWinAmount * array_sum($multipliers);
        }

        // Используем блоки из первого спина для reveal
        $finalBlocks = $allSpinsData[0]['blocks'];

        // 3. Reveal с anticipation
        $state[] = [
            'index' => $stateIndex++,
            'anticipation' => $anticipation,
            'blocks' => $finalBlocks,
            'board' => $initialBoard,
            'type' => 'reveal',
        ];

        // 4. Bonus enter
        $state[] = [
            'index' => $stateIndex++,
            'bonusType' => 'Bonus',
            'freeSpinCount' => $freeSpinCount,
            'positions' => $bonusPositions,
            'type' => 'bonusEnter',
        ];

        // Формируем state из сохраненных данных
        $currentTotalWin = 0.0;

        // Первый спин
        $spinData = $allSpinsData[0];
        $currentTotalWin += $spinData['spinWin'];
        $state[] = [
            'index' => $stateIndex++,
            'pickaxePhase' => $spinData['pickaxePhase'],
            'tntPhase' => $spinData['tntPhase'],
            'type' => 'mine',
        ];

        if ($spinData['spinWin'] > 0) {
            $state[] = [
                'index' => $stateIndex++,
                'totalWin' => round($currentTotalWin, 2),
                'type' => 'totalWin',
            ];
        }

        // Free spins (раунды 2-5, индексы 1-4)
        for ($spin = 1; $spin < $freeSpinCount; $spin++) {
            $freeSpinsRemaining = $freeSpinCount - $spin - 1; // Осталось раундов после текущего
            $spinData = $allSpinsData[$spin];

            // Bonus reveal - новые кирки на той же карте
            $state[] = [
                'index' => $stateIndex++,
                'anticipation' => 0,
                'board' => $spinData['board'],
                'freeSpinsRemaining' => $freeSpinsRemaining,
                'type' => 'bonusReveal',
            ];

            // Mine phase
            $state[] = [
                'index' => $stateIndex++,
                'pickaxePhase' => $spinData['pickaxePhase'],
                'tntPhase' => $spinData['tntPhase'],
                'type' => 'mine',
            ];

            $currentTotalWin += $spinData['spinWin'];

            // Показываем totalWin после каждого раунда (даже если spinWin = 0)
            $state[] = [
                'index' => $stateIndex++,
                'totalWin' => round($currentTotalWin, 2),
                'type' => 'totalWin',
            ];
        }

        // 8. Final win (с множителями из сундуков если нужно)
        $state[] = [
            'index' => $stateIndex++,
            'baseWinAmount' => round($baseWinAmount, 2),
            'finalWin' => round($finalWinAmount, 2),
            'multipliers' => $multipliers,
            'type' => 'finalWin',
        ];

        // 9. Bonus exit
        $state[] = [
            'index' => $stateIndex++,
            'totalSpins' => $freeSpinCount,
            'totalWin' => round($finalWinAmount, 2),
            'type' => 'bonusExit',
        ];

        // Обновляем баланс с выигрышем (с учётом множителей)
        $this->session->balance += (int) round($this->bet * $finalWinAmount);
        $this->session->save();

        return [
            'balance' => [
                'amount' => $this->session->balance,
                'currency' => 'RUB',
            ],
            'round' => [
                'betID' => $seed,
                'amount' => $this->bet,
                'payout' => (int) round($this->bet * $finalWinAmount),
                'payoutMultiplier' => round($finalWinAmount, 2),
                'active' => $finalWinAmount > 0,
                'state' => $state,
                'mode' => 'BONUS',
                'event' => null,
            ],
        ];
    }

    /**
     * Генерирует массив множителей из сундуков для достижения нужного общего множителя
     * @param float $requiredMultiplier - нужный общий множитель (сумма всех множителей)
     * @return array - массив множителей (например [3, 2] для x5)
     */
    private function generateChestMultipliers(float $requiredMultiplier): array
    {
        // Доступные множители из сундуков
        $availableMultipliers = [2, 3, 5, 10];
        $multipliers = [];
        $remaining = $requiredMultiplier;

        // Ограничиваем количество сундуков (максимум 5)
        $maxChests = 5;
        $chestCount = 0;

        while ($remaining > 1 && $chestCount < $maxChests) {
            // Выбираем подходящий множитель
            $bestMultiplier = null;

            // Сначала пробуем найти точное совпадение
            foreach ($availableMultipliers as $mult) {
                if (abs($mult - $remaining) < 0.5) {
                    $bestMultiplier = $mult;
                    break;
                }
            }

            // Если точного нет - берём максимальный который меньше remaining
            if ($bestMultiplier === null) {
                foreach (array_reverse($availableMultipliers) as $mult) {
                    if ($mult <= $remaining) {
                        $bestMultiplier = $mult;
                        break;
                    }
                }
            }

            // Если всё ещё нет - берём минимальный
            if ($bestMultiplier === null) {
                $bestMultiplier = $availableMultipliers[0]; // 2
            }

            $multipliers[] = $bestMultiplier;
            $remaining -= $bestMultiplier;
            $chestCount++;
        }

        // Если массив пустой, добавляем минимальный множитель
        if (empty($multipliers)) {
            $multipliers[] = max(2, (int) round($requiredMultiplier));
        }

        return $multipliers;
    }

    private function getRound(): array
    {
        $seed = $this->generateSeed(); // ОДИН seed на весь раунд

        $board = null;
        $blocks = null;
        $pickaxePhase = null;
        $tntPhase = null;
        $totalWin = 0.0;

        // Если задан множитель, перебираем варианты до получения нужного выигрыша
        if ($this->multiplierSeed !== null) {
            $targetMultiplier = (float) $this->multiplierSeed;
            $maxAttempts = 1000000; // Ограничение попыток
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                // Генерируем случайно с умной подстройкой под целевой множитель
                $board = $this->generateBoard($targetMultiplier);
                $blocks = $this->generateBlocks($targetMultiplier);

                $pickaxePhase = $this->mine($blocks, $board);
                $tntPhase = $this->tnt($blocks, $board, $pickaxePhase);
                $totalWin = $this->calculateWin($pickaxePhase, $tntPhase);

                // Проверяем, совпадает ли выигрыш с целевым множителем (с небольшой погрешностью)
                if (abs($totalWin - $targetMultiplier) < 0.01) {
                    break; // Нашли нужный результат
                }

                $attempt++;
            }
        } else {
            $board = $this->generateBoard();
            $blocks = $this->generateBlocks();

            $pickaxePhase = $this->mine($blocks, $board);
            $tntPhase = $this->tnt($blocks, $board, $pickaxePhase);
            $totalWin = $this->calculateWin($pickaxePhase, $tntPhase);
        }

        // Проверяем выпала ли бонуска (3+ символа "s" на board)
        $bonusCount = substr_count($board, 's');
        $isBonusTriggered = $bonusCount >= 3;

        // Находим позиции бонусных символов
        $bonusPositions = [];
        if ($isBonusTriggered) {
            $boardRows = str_split($board, 3);
            foreach ($boardRows as $reel => $row) {
                $rowSymbols = str_split($row);
                foreach ($rowSymbols as $rowIndex => $symbol) {
                    if ($symbol === 's') {
                        $bonusPositions[] = [$reel, $rowIndex];
                    }
                }
            }
        }

        $round = [
            'balance' => [
                'amount' => $this->session->balance,
                'currency' => 'RUB',
            ],
            'round' => [
                'betID' => $seed,
                'amount' => $this->bet,
                'payout' => (int) round($this->bet * $totalWin),
                'payoutMultiplier' => $totalWin,
                'active' => $totalWin > 0 || $isBonusTriggered,
                'state' => [
                    [
                        'index' => 0,
                        's' => $seed,
                        'type' => 's',
                    ],
                    [
                        'index' => 1,
                        'anticipation' => $bonusCount, // Количество бонусных символов
                        'blocks' => $blocks,
                        'board' => $board,
                        'type' => 'reveal',
                    ],
                    [
                        'index' => 2,
                        'pickaxePhase' => $pickaxePhase,
                        'tntPhase' => $tntPhase,
                        'type' => 'mine',
                    ],
                ],
                'mode' => 'BASE',
                'event' => $isBonusTriggered ? 'TRIGGER_BONUS' : null,
            ],
        ];

        if ($totalWin > 0) {
            $round['round']['state'][] = [
                'index' => count($round['round']['state']),
                'totalWin' => $totalWin,
                'type' => 'totalWin',
            ];
        }

        $round['round']['state'][] = [
            'index' => count($round['round']['state']),
            'baseWinAmount' => 0.0,
            'finalWin' => $totalWin,
            'multipliers' => [],
            'type' => 'finalWin',
        ];

        // Если выпала бонуска - добавляем bonusEnter
        if ($isBonusTriggered) {
            $round['round']['state'][] = [
                'index' => count($round['round']['state']),
                'bonusType' => 'Bonus',
                'freeSpinCount' => 5,
                'positions' => $bonusPositions,
                'type' => 'bonusEnter',
            ];
        }

        return $round;
    }

    private function pickaxePhase()
    {
        return [
            [
                'brokenBlocks' => [
                    0,
                    1
                ],
                'pickaxes' => [
                    [
                        'breakBlock' => 1,
                        'payout' => 0,
                        'row' => 1,
                        'symbol' => '4'
                    ]
                ],
                'reel' => 0
            ],
            [
                'brokenBlocks' => [
                    12,
                    13
                ],
                'pickaxes' => [
                    [
                        'breakBlock' => 12,
                        'payout' => 0,
                        'row' => 0,
                        'symbol' => '5'
                    ],
                    [
                        'breakBlock' => 13,
                        'payout' => 0,
                        'row' => 1,
                        'symbol' => '5'
                    ]
                ],
                'reel' => 2
            ],
            [
                'brokenBlocks' => [
                    24
                ],
                'pickaxes' => [
                    [
                        'breakBlock' => 25,
                        'payout' => 0,
                        'row' => 0,
                        'symbol' => '4'
                    ]
                ],
                'reel' => 4
            ]
        ];
    }
    private function calculateWin(array $pickaxePhase, array $tntPhase = []): float
    {
        $totalWin = 0.0;

        foreach ($pickaxePhase as $reel) {
            if (!isset($reel['pickaxes']) || !is_array($reel['pickaxes'])) {
                continue;
            }
            foreach ($reel['pickaxes'] as $pickaxe) {
                if (isset($pickaxe['payout'])) {
                    $totalWin += (float) $pickaxe['payout'];
                }
            }
        }

        foreach ($tntPhase as $tnt) {
            if (isset($tnt['payout'])) {
                $totalWin += (float) $tnt['payout'];
            }
        }

        return round($totalWin, 2);
    }

    private function generateBoard(?float $targetMultiplier = null): string
    {
        // x – пусто
        // 1 – TNT
        // 2,3,4,5 – кирки (2=5 урона, 3=3 урона, 4=2 урона, 5=1 урон)
        // s – бонусный символ (3 штуки = бонуска)

        $board = '';

        // 0.5% шанс на бонуску (3 символа "s")
        if (mt_rand(1, 1000) <= 5) { // 5/1000 = 0.5%
            return $this->generateBonusBoard();
        }

        if ($targetMultiplier !== null && $targetMultiplier > 10) {
            // Для больших множителей увеличиваем вероятность мощных кирок и TNT
            $boostFactor = min(($targetMultiplier / 100), 0.7); // Максимум 70% буст

            for ($i = 0; $i < 15; $i++) {
                // Подстраиваем веса: больше мощных кирок и TNT для больших множителей
                $weights = [
                    'x' => (int)(30 * (1 - $boostFactor)),
                    '1' => (int)(10 * (1 + $boostFactor * 1.5)), // TNT
                    '2' => (int)(15 * (1 + $boostFactor * 1.5)), // Мощная кирка (5 урона)
                    '3' => (int)(15 * (1 + $boostFactor * 0.5)), // Кирка (3 урона)
                    '4' => (int)(15 * (1 - $boostFactor * 0.3)), // Кирка (2 урона)
                    '5' => (int)(10 * (1 - $boostFactor * 0.5)), // Кирка (1 урон)
                ];

                $board .= $this->weightedRandom($weights);
            }
        } else {
            // Обычная генерация
            $symbols = ['x', 'x', '1', '2', '4', '5'];
            for ($i = 0; $i < 15; $i++) {
                $board .= $symbols[array_rand($symbols)];
            }
        }

        return $board;
    }

    /**
     * Генерирует board для бонусного раунда - распределяет кирки равномерно по всем раундам
     * @param array $alreadyBroken - массив индексов уже сломанных блоков
     * @param int $currentRound - текущий раунд (0-4)
     * @param int $totalRounds - всего раундов (5)
     * @param float|null $targetMultiplier - целевой множитель для буста
     * @return string board (15 символов)
     */
    private function generateBonusBoardForRound(array $alreadyBroken, int $currentRound, int $totalRounds, ?float $targetMultiplier = null): string
    {
        $board = '';

        // Считаем сколько блоков осталось
        $totalBlocks = 30;
        $brokenCount = count($alreadyBroken);
        $remainingBlocks = $totalBlocks - $brokenCount;

        // Сколько раундов осталось (включая текущий)
        $remainingRounds = $totalRounds - $currentRound;

        // Целевое количество блоков для разрушения в этом раунде
        // Распределяем оставшиеся блоки равномерно по оставшимся раундам
        $targetBlocksThisRound = $remainingRounds > 0 ? ceil($remainingBlocks / $remainingRounds) : $remainingBlocks;

        // Ограничиваем минимум 2-3 блока, максимум 8-10 блоков за раунд
        $targetBlocksThisRound = max(2, min(8, $targetBlocksThisRound));

        // Рассчитываем силу кирок на основе целевого количества блоков
        // Чем больше блоков нужно сломать - тем сильнее кирки
        $pickaxeStrength = $this->calculatePickaxeStrength($targetBlocksThisRound, $remainingBlocks);

        // Проверяем каждый reel (5 reels по 3 позиции на board)
        for ($reel = 0; $reel < 5; $reel++) {
            // Считаем несломанные блоки в этом столбце
            $unbrokenInReel = 0;
            for ($depth = 0; $depth < 6; $depth++) {
                $blockIndex = $reel * 6 + $depth;
                if (!in_array($blockIndex, $alreadyBroken)) {
                    $unbrokenInReel++;
                }
            }

            // Генерируем 3 позиции для этого reel
            for ($pos = 0; $pos < 3; $pos++) {
                if ($unbrokenInReel === 0) {
                    // Все блоки в столбце сломаны - ставим пустоту
                    $board .= 'x';
                } else {
                    // Есть несломанные блоки - генерируем кирку с учётом силы
                    $board .= $this->generatePickaxeByStrength($pickaxeStrength, $targetMultiplier);
                }
            }
        }

        return $board;
    }

    /**
     * Рассчитывает силу кирок для раунда
     * @param int $targetBlocks - сколько блоков нужно сломать
     * @param int $remainingBlocks - сколько блоков осталось
     * @return float - коэффициент силы (0.0 - слабые, 1.0 - сильные)
     */
    private function calculatePickaxeStrength(int $targetBlocks, int $remainingBlocks): float
    {
        if ($remainingBlocks <= 0) {
            return 0.0;
        }

        // Базовая сила на основе доли блоков для разрушения
        $ratio = $targetBlocks / max(1, $remainingBlocks);

        // Нормализуем к диапазону 0.2 - 0.8
        // Не даём слишком слабые или слишком сильные кирки
        return max(0.2, min(0.8, $ratio));
    }

    /**
     * Генерирует символ кирки на основе силы
     * @param float $strength - коэффициент силы (0.0 - 1.0)
     * @param float|null $targetMultiplier - целевой множитель
     * @return string - символ кирки
     */
    private function generatePickaxeByStrength(float $strength, ?float $targetMultiplier = null): string
    {
        // Базовые веса
        // x - пусто, 1 - TNT, 2 - 5 урона, 3 - 3 урона, 4 - 2 урона, 5 - 1 урон

        // Чем выше strength, тем меньше пустых и слабых, больше сильных
        $emptyWeight = (int)(40 * (1 - $strength));      // 40 -> 8 при strength 0.8
        $tntWeight = (int)(5 + 10 * $strength);          // 5 -> 13
        $strongWeight = (int)(5 + 15 * $strength);       // 5 -> 17 (5 урона)
        $mediumWeight = (int)(10 + 10 * $strength);      // 10 -> 18 (3 урона)
        $weakWeight = (int)(15 * (1 - $strength * 0.5)); // 15 -> 9 (2 урона)
        $veryWeakWeight = (int)(10 * (1 - $strength));   // 10 -> 2 (1 урон)

        // Если есть целевой множитель, корректируем
        if ($targetMultiplier !== null && $targetMultiplier > 20) {
            $boostFactor = min(($targetMultiplier / 150), 0.5);
            $emptyWeight = (int)($emptyWeight * (1 - $boostFactor));
            $tntWeight = (int)($tntWeight * (1 + $boostFactor));
            $strongWeight = (int)($strongWeight * (1 + $boostFactor));
        }

        $weights = [
            'x' => max(1, $emptyWeight),
            '1' => max(1, $tntWeight),
            '2' => max(1, $strongWeight),
            '3' => max(1, $mediumWeight),
            '4' => max(1, $weakWeight),
            '5' => max(1, $veryWeakWeight),
        ];

        return $this->weightedRandom($weights);
    }

    private function generateBonusBoard(): string
    {
        // Генерируем доску с бонусными символами 's'
        // Нужно гарантировать 3-5 бонусных символов
        $bonusCount = mt_rand(3, 5);
        $board = str_repeat('x', 15); // Начинаем с пустой доски

        // Размещаем бонусные символы случайно
        $positions = [];
        while (count($positions) < $bonusCount) {
            $pos = mt_rand(0, 14);
            if (!in_array($pos, $positions)) {
                $positions[] = $pos;
            }
        }

        foreach ($positions as $pos) {
            $board[$pos] = 's';
        }

        // Заполняем остальные позиции обычными символами
        $symbols = ['x', 'x', '1', '2', '4', '5'];
        for ($i = 0; $i < 15; $i++) {
            if ($board[$i] === 'x' && !in_array($i, $positions)) {
                $board[$i] = $symbols[array_rand($symbols)];
            }
        }

        return $board;
    }

    private function mine(string $blocks, string $board): array
    {
        if (strlen($blocks) !== 30 || strlen($board) !== 15) {
            return [];
        }

        $blockRows = str_split($blocks, 6);
        $boardRows = str_split($board, 3);

        $pickaxePhase = [];

        foreach ($blockRows as $reel => $row) {
            $rowBlocks = str_split($row);
            $boardRow = str_split($boardRows[$reel]);

            $pickaxes = [];
            foreach ($boardRow as $col => $symbol) {
                if ($symbol !== 'x' && isset($this->pickaxeHits[$symbol])) {
                    $pickaxes[] = [
                        'symbol' => $symbol,
                        'damage' => $this->pickaxeHits[$symbol],
                        'col' => $col,
                    ];
                }
            }

            if (!$pickaxes) {
                continue;
            }

            usort($pickaxes, function ($a, $b) {
                if ($a['damage'] === $b['damage']) {
                    return $a['col'] <=> $b['col'];
                }
                return $a['damage'] <=> $b['damage'];
            });

            $currentIndex = 0;       // накопительное разрушение слева направо
            $allBroken = [];
            $pickaxesOut = [];

            // Track remaining HP for each block (cumulative damage)
            $blockHPRemaining = [];

            foreach ($pickaxes as $pickaxe) {
                $damage = $pickaxe['damage'];

                $localBroken = [];
                $localPayout = 0.0;
                $is_broken = false;
                $lastBlock = null;
                $lastHP = null;
                $lastBlockIndex = null; // Track the last block index that was hit

                while ($currentIndex < 6 && $damage > 0) {
                    $block = $rowBlocks[$currentIndex];
                    $baseHP = $this->blockHP[$block] ?? null;
                    if ($baseHP === null) {
                        break;
                    }

                    // Get remaining HP for this block (accounting for previous damage)
                    if (!isset($blockHPRemaining[$currentIndex])) {
                        $blockHPRemaining[$currentIndex] = $baseHP;
                    }
                    $hp = $blockHPRemaining[$currentIndex];

                    // Skip if block is already broken (HP <= 0)
                    if ($hp <= 0) {
                        $currentIndex++;
                        continue;
                    }

                    $globalIndex = $reel * 6 + $currentIndex;

                    // Deal 1 damage per hit
                    $hp--;
                    $damage--;
                    $blockHPRemaining[$currentIndex] = $hp;

                    // Track the last block that was hit
                    $lastBlockIndex = $globalIndex;

                    // If block is broken (HP reached 0), add to broken list
                    if ($hp <= 0) {
                        if (!in_array($globalIndex, $localBroken)) {
                            $localBroken[] = $globalIndex;
                            $is_broken = true;
                        }
                        if (!in_array($globalIndex, $allBroken)) {
                            $allBroken[] = $globalIndex;
                        }

                        if (isset($this->blockPayout[$block])) {
                            // Суммируем выплаты по всем сломанным блокам киркой
                            $localPayout += (float) $this->blockPayout[$block];
                        }
                    }

                    $lastBlock = $block;
                    $lastHP = $hp;

                    // Move to next block only if current block is fully broken AND we still have damage
                    // If block is not broken (hp > 0), we'll continue hitting it in next iteration
                    if ($hp <= 0 && $damage > 0) {
                        $currentIndex++; // Move to next block only if we have more damage
                    }
                    // If hp > 0, we stay on the same block and will hit it again in next iteration
                }

                if ($localBroken || $lastBlockIndex !== null) {
                    $pickaxesOut[] = [
                        'breakBlock' => $lastBlockIndex !== null ? $lastBlockIndex : max(end($localBroken), $reel * 6 + $pickaxe['col']),
                        'payout' => (float) $localPayout,
                        'row' => $pickaxe['col'],
                        'symbol' => $pickaxe['symbol'],
                        'hp' => $lastHP,
                        'damage' => $pickaxe['damage'],
                        'block' => $lastBlock,
                        'is_broken' => $is_broken,
                    ];
                }
            }

            if ($pickaxesOut) {
                $allBroken = array_values(array_unique($allBroken));
                sort($allBroken);

                $pickaxePhase[] = [
                    'brokenBlocks' => $allBroken,
                    'pickaxes' => $pickaxesOut,
                    'block' => $block,
                    'reel' => $reel,
                ];
            }
        }

        return $pickaxePhase;
    }

    /**
     * Bonus Mine - кирки для бонусной игры с учетом уже сломанных блоков
     * @param string $blocks - карта блоков (30 символов)
     * @param string $board - текущие кирки (15 символов)
     * @param array &$alreadyBroken - массив индексов уже сломанных блоков (по ссылке, обновляется)
     * @param array &$blockHPState - состояние HP блоков между раундами (по ссылке)
     * @return array pickaxePhase
     */
    private function bonusMine(string $blocks, string $board, array &$alreadyBroken, array &$blockHPState): array
    {
        if (strlen($blocks) !== 30 || strlen($board) !== 15) {
            return [];
        }

        $blockRows = str_split($blocks, 6);
        $boardRows = str_split($board, 3);

        $pickaxePhase = [];

        foreach ($blockRows as $reel => $row) {
            $rowBlocks = str_split($row);
            $boardRow = str_split($boardRows[$reel]);

            $pickaxes = [];
            foreach ($boardRow as $col => $symbol) {
                if ($symbol !== 'x' && isset($this->pickaxeHits[$symbol])) {
                    $pickaxes[] = [
                        'symbol' => $symbol,
                        'damage' => $this->pickaxeHits[$symbol],
                        'col' => $col,
                    ];
                }
            }

            if (!$pickaxes) {
                continue;
            }

            usort($pickaxes, function ($a, $b) {
                if ($a['damage'] === $b['damage']) {
                    return $a['col'] <=> $b['col'];
                }
                return $a['damage'] <=> $b['damage'];
            });

            $allBroken = [];
            $pickaxesOut = [];

            // Находим первый несломанный блок в этом ряду
            $currentIndex = 0;
            for ($i = 0; $i < 6; $i++) {
                $globalIndex = $reel * 6 + $i;
                if (!in_array($globalIndex, $alreadyBroken)) {
                    $currentIndex = $i;
                    break;
                }
                if ($i === 5) {
                    $currentIndex = 6; // Все блоки сломаны
                }
            }

            foreach ($pickaxes as $pickaxe) {
                $damage = $pickaxe['damage'];

                $localBroken = [];
                $localPayout = 0.0;
                $is_broken = false;
                $lastBlock = null;
                $lastHP = null;
                $lastBlockIndex = null;

                while ($currentIndex < 6 && $damage > 0) {
                    $globalIndex = $reel * 6 + $currentIndex;

                    // Пропускаем уже сломанные блоки
                    if (in_array($globalIndex, $alreadyBroken)) {
                        $currentIndex++;
                        continue;
                    }

                    $block = $rowBlocks[$currentIndex];
                    $baseHP = $this->blockHP[$block] ?? null;
                    if ($baseHP === null) {
                        break;
                    }

                    // Получаем текущее HP из состояния или инициализируем
                    if (!isset($blockHPState[$globalIndex])) {
                        $blockHPState[$globalIndex] = $baseHP;
                    }
                    $hp = $blockHPState[$globalIndex];

                    // Пропускаем если блок уже сломан
                    if ($hp <= 0) {
                        $currentIndex++;
                        continue;
                    }

                    // 1 урон за удар
                    $hp--;
                    $damage--;
                    $blockHPState[$globalIndex] = $hp;

                    $lastBlockIndex = $globalIndex;

                    // Если блок сломан
                    if ($hp <= 0) {
                        if (!in_array($globalIndex, $localBroken)) {
                            $localBroken[] = $globalIndex;
                            $is_broken = true;
                        }
                        if (!in_array($globalIndex, $allBroken)) {
                            $allBroken[] = $globalIndex;
                        }
                        // Добавляем в общий список сломанных
                        if (!in_array($globalIndex, $alreadyBroken)) {
                            $alreadyBroken[] = $globalIndex;
                        }

                        if (isset($this->blockPayout[$block])) {
                            $localPayout += (float) $this->blockPayout[$block];
                        }
                    }

                    $lastBlock = $block;
                    $lastHP = $hp;

                    // Переходим к следующему блоку только если текущий сломан и есть урон
                    if ($hp <= 0 && $damage > 0) {
                        $currentIndex++;
                        // Пропускаем уже сломанные
                        while ($currentIndex < 6 && in_array($reel * 6 + $currentIndex, $alreadyBroken)) {
                            $currentIndex++;
                        }
                    }
                }

                if ($localBroken || $lastBlockIndex !== null) {
                    $pickaxesOut[] = [
                        'breakBlock' => $lastBlockIndex !== null ? $lastBlockIndex : max(end($localBroken), $reel * 6 + $pickaxe['col']),
                        'payout' => (float) $localPayout,
                        'row' => $pickaxe['col'],
                        'symbol' => $pickaxe['symbol'],
                        'hp' => $lastHP,
                        'damage' => $pickaxe['damage'],
                        'block' => $lastBlock,
                        'is_broken' => $is_broken,
                    ];
                }
            }

            if ($pickaxesOut) {
                $allBroken = array_values(array_unique($allBroken));
                sort($allBroken);

                $pickaxePhase[] = [
                    'brokenBlocks' => $allBroken,
                    'pickaxes' => $pickaxesOut,
                    'reel' => $reel,
                ];
            }
        }

        return $pickaxePhase;
    }

    /**
     * Bonus TNT - взрывы для бонусной игры с учетом уже сломанных блоков
     * @param string $blocks - карта блоков (30 символов)
     * @param string $board - текущие кирки (15 символов)
     * @param array &$alreadyBroken - массив индексов уже сломанных блоков (по ссылке, обновляется)
     * @param array &$blockHPState - состояние HP блоков между раундами (по ссылке)
     * @return array tntPhase
     */
    private function bonusTnt(string $blocks, string $board, array &$alreadyBroken, array &$blockHPState): array
    {
        if (strlen($blocks) !== 30 || strlen($board) !== 15) {
            return [];
        }

        $blockRows = str_split($blocks, 6);
        $boardRows = str_split($board, 3);

        $tntPhase = [];
        foreach ($boardRows as $reel => $boardRow) {
            $rowSymbols = str_split($boardRow);
            foreach ($rowSymbols as $boardRowIndex => $symbol) {
                if ($symbol !== '1') {
                    continue;
                }

                // TNT падает до первой неразрушенной клетки в столбце
                $landingDepth = null;
                for ($depth = 0; $depth < 6; $depth++) {
                    $gIndex = $reel * 6 + $depth;
                    // Проверяем что блок не в списке сломанных И имеет HP > 0
                    if (!in_array($gIndex, $alreadyBroken)) {
                        if (!isset($blockHPState[$gIndex])) {
                            $blockType = $blocks[$gIndex] ?? null;
                            if ($blockType && isset($this->blockHP[$blockType])) {
                                $blockHPState[$gIndex] = $this->blockHP[$blockType];
                            }
                        }
                        if (isset($blockHPState[$gIndex]) && $blockHPState[$gIndex] > 0) {
                            $landingDepth = $depth;
                            break;
                        }
                    }
                }
                if ($landingDepth === null) {
                    continue; // столбец пуст — нечего взрывать
                }

                $tntBlockIndex = $reel * 6 + $landingDepth;
                $neighbors = [];

                // тот же столбец (вверх/вниз от landingDepth)
                if ($landingDepth > 0)
                    $neighbors[] = $reel * 6 + ($landingDepth - 1);
                if ($landingDepth < 5)
                    $neighbors[] = $reel * 6 + ($landingDepth + 1);

                // соседние столбцы
                if ($reel > 0) {
                    if ($landingDepth > 0)
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth - 1);
                    $neighbors[] = ($reel - 1) * 6 + $landingDepth;
                    if ($landingDepth < 5)
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth + 1);
                }
                if ($reel < 4) {
                    if ($landingDepth > 0)
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth - 1);
                    $neighbors[] = ($reel + 1) * 6 + $landingDepth;
                    if ($landingDepth < 5)
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth + 1);
                }

                $brokenBlocks = [];
                $payout = 0.0;
                $didDamage = false;

                $targets = array_merge([$tntBlockIndex], $neighbors);

                foreach ($targets as $targetIndex) {
                    // Пропускаем уже сломанные блоки
                    if (in_array($targetIndex, $alreadyBroken)) {
                        continue;
                    }

                    if (!isset($blockHPState[$targetIndex])) {
                        $blockType = $blocks[$targetIndex] ?? null;
                        if ($blockType && isset($this->blockHP[$blockType])) {
                            $blockHPState[$targetIndex] = $this->blockHP[$blockType];
                        } else {
                            continue;
                        }
                    }

                    $hp = $blockHPState[$targetIndex];
                    if ($hp <= 0) {
                        continue;
                    }

                    $hp -= 2; // TNT урон
                    $blockHPState[$targetIndex] = $hp;
                    $didDamage = true;

                    if ($hp <= 0) {
                        $brokenBlocks[] = $targetIndex;
                        // Добавляем в общий список сломанных
                        if (!in_array($targetIndex, $alreadyBroken)) {
                            $alreadyBroken[] = $targetIndex;
                        }

                        // тип блока для выплаты
                        $blockReel = intdiv($targetIndex, 6);
                        $blockDepth = $targetIndex % 6;
                        $blockType = $blockRows[$blockReel][$blockDepth] ?? null;
                        if ($blockType && isset($this->blockPayout[$blockType])) {
                            $payout += (float) $this->blockPayout[$blockType];
                        }
                    }
                }

                if ($didDamage) {
                    sort($brokenBlocks);
                    $tntPhase[] = [
                        'blockIndex' => $tntBlockIndex,
                        'brokenBlocks' => $brokenBlocks,
                        'payout' => round($payout, 1),
                        'reel' => $reel,
                        'row' => $boardRowIndex,
                    ];
                }
            }
        }

        return $tntPhase;
    }

    private function tnt(string $blocks, string $board, array $pickaxePhase): array
    {
        if (strlen($blocks) !== 30 || strlen($board) !== 15) {
            return [];
        }

        $blockRows = str_split($blocks, 6);
        $boardRows = str_split($board, 3);

        // 1) Посчитать оставшееся HP после кирок (симуляция той же логики)
        $blockHPRemaining = [];
        for ($i = 0; $i < 30; $i++) {
            $blockType = $blocks[$i] ?? null;
            if ($blockType !== null && isset($this->blockHP[$blockType])) {
                $blockHPRemaining[$i] = $this->blockHP[$blockType];
            }
        }

        foreach ($blockRows as $reel => $row) {
            $rowBlocks = str_split($row);
            $boardRow = str_split($boardRows[$reel]);
            $pickaxes = [];
            foreach ($boardRow as $col => $symbol) {
                if ($symbol !== 'x' && isset($this->pickaxeHits[$symbol])) {
                    $pickaxes[] = [
                        'symbol' => $symbol,
                        'damage' => $this->pickaxeHits[$symbol],
                        'col' => $col,
                    ];
                }
            }
            if (!$pickaxes) {
                continue;
            }
            usort($pickaxes, function ($a, $b) {
                return $a['damage'] === $b['damage']
                    ? ($a['col'] <=> $b['col'])
                    : ($a['damage'] <=> $b['damage']);
            });

            $currentIndex = 0;
            foreach ($pickaxes as $pickaxe) {
                $damage = $pickaxe['damage'];
                while ($currentIndex < 6 && $damage > 0) {
                    $globalIndex = $reel * 6 + $currentIndex;
                    if (!isset($blockHPRemaining[$globalIndex])) {
                        break;
                    }
                    $hp = $blockHPRemaining[$globalIndex];
                    if ($hp <= 0) {
                        $currentIndex++;
                        continue;
                    }
                    // 1 урон за удар
                    $hp--;
                    $damage--;
                    $blockHPRemaining[$globalIndex] = $hp;

                    // переход к следующему блоку только если сломан и урон остался
                    if ($hp <= 0 && $damage > 0) {
                        $currentIndex++;
                    }
                }
            }
        }

        // 2) TNT: символ '1' на борде, наносит 2 урона по соседям
        $tntPhase = [];
        foreach ($boardRows as $reel => $boardRow) {
            $rowSymbols = str_split($boardRow);
            foreach ($rowSymbols as $boardRowIndex => $symbol) {
                if ($symbol !== '1') {
                    continue;
                }

                // TNT падает до первой неразрушенной клетки в столбце
                $landingDepth = null;
                for ($depth = 0; $depth < 6; $depth++) {
                    $gIndex = $reel * 6 + $depth;
                    if (isset($blockHPRemaining[$gIndex]) && $blockHPRemaining[$gIndex] > 0) {
                        $landingDepth = $depth;
                        break;
                    }
                }
                if ($landingDepth === null) {
                    continue; // столбец пуст — нечего взрывать
                }

                $tntBlockIndex = $reel * 6 + $landingDepth;
                $neighbors = [];

                // тот же столбец (вверх/вниз от landingDepth)
                if ($landingDepth > 0)
                    $neighbors[] = $reel * 6 + ($landingDepth - 1);
                if ($landingDepth < 5)
                    $neighbors[] = $reel * 6 + ($landingDepth + 1);

                // соседние столбцы (диагонали + вертикаль) от landingDepth
                if ($reel > 0) {
                    if ($landingDepth > 0)
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth - 1);
                    $neighbors[] = ($reel - 1) * 6 + $landingDepth;
                    if ($landingDepth < 5)
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth + 1);
                }
                if ($reel < 4) {
                    if ($landingDepth > 0)
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth - 1);
                    $neighbors[] = ($reel + 1) * 6 + $landingDepth;
                    if ($landingDepth < 5)
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth + 1);
                }

                $brokenBlocks = [];
                $payout = 0.0;
                $didDamage = false;

                // TNT также бьёт по своей клетке
                $targets = array_merge([$tntBlockIndex], $neighbors);

                foreach ($targets as $targetIndex) {
                    if (!isset($blockHPRemaining[$targetIndex])) {
                        continue;
                    }

                    $hp = $blockHPRemaining[$targetIndex];
                    if ($hp <= 0) {
                        continue;
                    }

                    $hp -= 2; // TNT урон
                    $blockHPRemaining[$targetIndex] = $hp;
                    $didDamage = true; // зафиксировали взрыв

                    if ($hp <= 0) {
                        $brokenBlocks[] = $targetIndex;

                        // тип блока для выплаты
                        $blockReel = intdiv($targetIndex, 6);
                        $blockDepth = $targetIndex % 6;
                        $blockType = $blockRows[$blockReel][$blockDepth] ?? null;
                        if ($blockType && isset($this->blockPayout[$blockType])) {
                            // Суммируем выплаты сломанных блоков (не максимум)
                            $payout += (float) $this->blockPayout[$blockType];
                        }
                    }
                }


                if ($didDamage) {
                    sort($brokenBlocks);
                    $tntPhase[] = [
                        'blockIndex' => $tntBlockIndex,
                        'brokenBlocks' => $brokenBlocks,
                        'payout' => round($payout, 1),
                        'reel' => $reel,
                        'row' => $boardRowIndex, // исходная позиция на борде
                    ];
                }
            }
        }

        return $tntPhase;
    }





    private function generateSeed(): string
    {
        // Generate a realistic seed value similar to the provider's format
        // The provider uses seeds like 75536, not MAX_VALUE placeholders
        // Generate a random seed in a reasonable range (e.g., 1 to 999999)
        return (int) mt_rand(1, 999999);
    }
    private function generateBlocks(?float $targetMultiplier = null): string
    {
        $blocks = '';

        // Определяем буст для ценных блоков на основе целевого множителя
        $boostFactor = 0;
        if ($targetMultiplier !== null && $targetMultiplier > 10) {
            $boostFactor = min(($targetMultiplier / 200), 0.8); // Максимум 80% буст
        }

        for ($reel = 0; $reel < 5; $reel++) {
            for ($depth = 0; $depth < 6; $depth++) {

                switch ($depth) {

                    case 0: // 1 ряд — всегда земля
                        $block = 'd';
                        break;

                    case 1: // 2 ряд — земля, редко камень
                        if ($boostFactor > 0) {
                            $block = $this->weightedRandom([
                                'd' => (int)(80 * (1 - $boostFactor * 0.3)),
                                'c' => (int)(20 * (1 + $boostFactor)),
                            ]);
                        } else {
                            $block = $this->weightedRandom([
                                'd' => 80,
                                'c' => 20,
                            ]);
                        }
                        break;

                    case 2: // 3 ряд — иногда земля, чаще камень или руда
                        if ($boostFactor > 0) {
                            $block = $this->weightedRandom([
                                'd' => (int)(20 * (1 - $boostFactor)),
                                'c' => (int)(50 * (1 - $boostFactor * 0.2)),
                                'r' => (int)(30 * (1 + $boostFactor * 1.5)),
                            ]);
                        } else {
                            $block = $this->weightedRandom([
                                'd' => 20,
                                'c' => 50,
                                'r' => 30,
                            ]);
                        }
                        break;

                    case 3: // 4 ряд — камень или руда
                        if ($boostFactor > 0) {
                            $block = $this->weightedRandom([
                                'c' => (int)(60 * (1 - $boostFactor * 0.3)),
                                'r' => (int)(40 * (1 + $boostFactor * 1.2)),
                            ]);
                        } else {
                            $block = $this->weightedRandom([
                                'c' => 60,
                                'r' => 40,
                            ]);
                        }
                        break;

                    case 4: // 5 ряд — руда / золото / алмаз
                        if ($boostFactor > 0) {
                            $block = $this->weightedRandom([
                                'r' => (int)(40 * (1 - $boostFactor * 0.5)),
                                'g' => (int)(40 * (1 + $boostFactor * 0.8)),
                                'm' => (int)(20 * (1 + $boostFactor * 2)),
                            ]);
                        } else {
                            $block = $this->weightedRandom([
                                'r' => 40,
                                'g' => 40,
                                'm' => 20,
                            ]);
                        }
                        break;

                    case 5: // 6 ряд — алмаз / обсидиан
                        if ($boostFactor > 0) {
                            $block = $this->weightedRandom([
                                'm' => (int)(60 * (1 - $boostFactor * 0.3)),
                                'o' => (int)(40 * (1 + $boostFactor * 1.5)),
                            ]);
                        } else {
                            $block = $this->weightedRandom([
                                'm' => 60,
                                'o' => 40,
                            ]);
                        }
                        break;
                }

                $blocks .= $block;
            }
        }

        return $blocks; // всегда 30
    }

    private function weightedRandom(array $weights): string
    {
        $sum = array_sum($weights);
        $rand = mt_rand(1, $sum);

        foreach ($weights as $key => $weight) {
            $rand -= $weight;
            if ($rand <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    public function bonusRound(Request $request){
        $total_map = $this->generateBlocks(5);
        $data = [
            'balance' => [
                'amount' => 900000000,
                'currency' => 'USD'
            ],
            'round' => [
                'betID' => 3147892704,
                'amount' => 1000000,
                'payout' => 2600000,
                'payoutMultiplier' => 2.6,
                'active' => true,
                'state' => [
                    [
                        'index' => 0,
                        's' => 14616498720613603000,
                        'type' => 's'
                    ],
                    [
                        'anticipation' => 3,
                        'blocks' => 'ddcrmgdccrgoddcrgmddcrmoddcrgo',
                        'board' => 'x5x5sx5xs5xxxs5',
                        'index' => 1,
                        'type' => 'reveal'
                    ],
                    [
                        'bonusType' => 'Bonus',
                        'freeSpinCount' => 5,
                        'index' => 2,
                        'positions' => [
                            [
                                1,
                                1
                            ],
                            [
                                2,
                                2
                            ],
                            [
                                4,
                                1
                            ]
                        ],
                        'type' => 'bonusEnter'
                    ],
                    [
                        'index' => 3,
                        'pickaxePhase' => [
                            [
                                'brokenBlocks' => [
                                    0
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 0,
                                        'payout' => 0,
                                        'row' => 1,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 0
                            ],
                            [
                                'brokenBlocks' => [
                                    6
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 6,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 1
                            ],
                            [
                                'brokenBlocks' => [
                                    12
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 12,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 2
                            ],
                            [
                                'brokenBlocks' => [
                                    18
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 18,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 3
                            ],
                            [
                                'brokenBlocks' => [
                                    24
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 24,
                                        'payout' => 0,
                                        'row' => 2,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 4
                            ]
                        ],
                        'tntPhase' => [],
                        'type' => 'mine'
                    ],
                    [
                        'index' => 4,
                        'totalWin' => 0,
                        'type' => 'totalWin'
                    ],
                    [
                        'anticipation' => 0,
                        'board' => 'x5x5x55xxx25xxx',
                        'freeSpinsRemaining' => 3,
                        'index' => 5,
                        'type' => 'bonusReveal'
                    ],
                    [
                        'index' => 6,
                        'pickaxePhase' => [
                            [
                                'brokenBlocks' => [
                                    1
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 1,
                                        'payout' => 0,
                                        'row' => 1,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 0
                            ],
                            [
                                'brokenBlocks' => [
                                    7
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 7,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ],
                                    [
                                        'breakBlock' => 7,
                                        'payout' => 0.1,
                                        'row' => 2,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 1
                            ],
                            [
                                'brokenBlocks' => [
                                    13
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 13,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 2
                            ],
                            [
                                'brokenBlocks' => [
                                    19,
                                    20
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 19,
                                        'payout' => 0,
                                        'row' => 2,
                                        'symbol' => '5'
                                    ],
                                    [
                                        'breakBlock' => 21,
                                        'payout' => 0.1,
                                        'row' => 1,
                                        'symbol' => '2'
                                    ]
                                ],
                                'reel' => 3
                            ]
                        ],
                        'tntPhase' => [],
                        'type' => 'mine'
                    ],
                    [
                        'index' => 7,
                        'totalWin' => 0.2,
                        'type' => 'totalWin'
                    ],
                    [
                        'anticipation' => 0,
                        'board' => '3xxxx2x5x54xxxx',
                        'freeSpinsRemaining' => 2,
                        'index' => 8,
                        'type' => 'bonusReveal'
                    ],
                    [
                        'index' => 9,
                        'pickaxePhase' => [
                            [
                                'brokenBlocks' => [
                                    2
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 3,
                                        'payout' => 0.1,
                                        'row' => 0,
                                        'symbol' => '3'
                                    ]
                                ],
                                'reel' => 0
                            ],
                            [
                                'brokenBlocks' => [
                                    8
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 9,
                                        'payout' => 0.1,
                                        'row' => 2,
                                        'symbol' => '2'
                                    ]
                                ],
                                'reel' => 1
                            ],
                            [
                                'brokenBlocks' => [],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 14,
                                        'payout' => 0,
                                        'row' => 1,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 2
                            ],
                            [
                                'brokenBlocks' => [
                                    21
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 21,
                                        'payout' => 1,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ],
                                    [
                                        'breakBlock' => 22,
                                        'payout' => 0,
                                        'row' => 1,
                                        'symbol' => '4'
                                    ]
                                ],
                                'reel' => 3
                            ]
                        ],
                        'tntPhase' => [],
                        'type' => 'mine'
                    ],
                    [
                        'index' => 10,
                        'totalWin' => 1.4,
                        'type' => 'totalWin'
                    ],
                    [
                        'anticipation' => 0,
                        'board' => 'xxxxxx54x55xxxx',
                        'freeSpinsRemaining' => 1,
                        'index' => 11,
                        'type' => 'bonusReveal'
                    ],
                    [
                        'index' => 12,
                        'pickaxePhase' => [
                            [
                                'brokenBlocks' => [
                                    14
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 14,
                                        'payout' => 0.1,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ],
                                    [
                                        'breakBlock' => 15,
                                        'payout' => 0,
                                        'row' => 1,
                                        'symbol' => '4'
                                    ]
                                ],
                                'reel' => 2
                            ],
                            [
                                'brokenBlocks' => [],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 22,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ],
                                    [
                                        'breakBlock' => 22,
                                        'payout' => 0,
                                        'row' => 1,
                                        'symbol' => '5'
                                    ]
                                ],
                                'reel' => 3
                            ]
                        ],
                        'tntPhase' => [],
                        'type' => 'mine'
                    ],
                    [
                        'index' => 13,
                        'totalWin' => 1.5,
                        'type' => 'totalWin'
                    ],
                    [
                        'anticipation' => 0,
                        'board' => 'xx1x3xxxxxxx5x3',
                        'freeSpinsRemaining' => 0,
                        'index' => 14,
                        'type' => 'bonusReveal'
                    ],
                    [
                        'index' => 15,
                        'pickaxePhase' => [
                            [
                                'brokenBlocks' => [
                                    9
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 10,
                                        'payout' => 1,
                                        'row' => 1,
                                        'symbol' => '3'
                                    ]
                                ],
                                'reel' => 1
                            ],
                            [
                                'brokenBlocks' => [
                                    25,
                                    26
                                ],
                                'pickaxes' => [
                                    [
                                        'breakBlock' => 25,
                                        'payout' => 0,
                                        'row' => 0,
                                        'symbol' => '5'
                                    ],
                                    [
                                        'breakBlock' => 27,
                                        'payout' => 0.1,
                                        'row' => 2,
                                        'symbol' => '3'
                                    ]
                                ],
                                'reel' => 4
                            ]
                        ],
                        'tntPhase' => [
                            [
                                'blockIndex' => 3,
                                'brokenBlocks' => [],
                                'payout' => 0,
                                'reel' => 0,
                                'row' => 2
                            ]
                        ],
                        'type' => 'mine'
                    ],
                    [
                        'index' => 16,
                        'totalWin' => 2.6,
                        'type' => 'totalWin'
                    ],
                    [
                        'baseWinAmount' => 2.6,
                        'finalWin' => 2.6,
                        'index' => 17,
                        'multipliers' => [],
                        'type' => 'finalWin'
                    ],
                    [
                        'index' => 18,
                        'totalSpins' => 5,
                        'totalWin' => 2.6,
                        'type' => 'bonusExit'
                    ]
                ],
                'mode' => 'BONUS',
                'event' => null
            ]
        ];
        return response()->json($data);
    }

}
