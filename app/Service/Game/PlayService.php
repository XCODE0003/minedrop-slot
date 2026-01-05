<?php
namespace App\Service\Game;

use App\Models\Bet;
use App\Models\SessionGame;

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
        's' => 0, // special (1 удар)
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
        // Баланс уже списан в контроллере
        // Выигрыш начисляется отдельным запросом после подтверждения результата
        return $this->getRound();
    }

    public function generateBonusRound($blocks, $board, $startIndex = 0, array &$hpState = [], float &$totalWinAccum = 0): array
    {
        ray(">>> generateBonusRound index=$startIndex", "blocks: $blocks", "board: $board");

        $pickaxePhase = $this->mine($blocks, $board, $hpState);

        // Собираем сломанные блоки из pickaxePhase и считаем payout
        $pickaxeBroken = [];
        $roundWin = 0.0;
        foreach ($pickaxePhase as $phase) {
            if (isset($phase['brokenBlocks']) && is_array($phase['brokenBlocks'])) {
                $pickaxeBroken = array_merge($pickaxeBroken, $phase['brokenBlocks']);
            }
            // Суммируем payout только от кирок, которые сломали блок
            if (isset($phase['pickaxes']) && is_array($phase['pickaxes'])) {
                foreach ($phase['pickaxes'] as $pickaxe) {
                    if (isset($pickaxe['payout']) && !empty($pickaxe['is_broken'])) {
                        $roundWin += (float) $pickaxe['payout'];
                        ray("Pickaxe payout: {$pickaxe['payout']} (block: {$pickaxe['block']}, reel: {$phase['reel']})");
                    }
                }
            }
        }

        // Обновляем blocks после pickaxePhase (заменяем сломанные на L)
        $blocksArray = str_split($blocks);
        foreach ($pickaxeBroken as $index) {
            if (isset($blocksArray[$index])) {
                $blocksArray[$index] = 'L';
            }
        }
        $blocksAfterPickaxe = implode('', $blocksArray);

        // Теперь вызываем tnt с обновленными блоками
        $tntPhase = $this->tnt($blocksAfterPickaxe, $board, $pickaxePhase, $hpState);

        // Собираем сломанные блоки из tntPhase и считаем payout
        $tntBroken = [];
        foreach ($tntPhase as $tnt) {
            if (isset($tnt['brokenBlocks']) && is_array($tnt['brokenBlocks'])) {
                $tntBroken = array_merge($tntBroken, $tnt['brokenBlocks']);
            }
            // Суммируем payout от TNT
            if (isset($tnt['payout'])) {
                $roundWin += (float) $tnt['payout'];
            }
        }

        // Обновляем blocks после tntPhase
        $blocksArray = str_split($blocksAfterPickaxe);
        foreach ($tntBroken as $index) {
            if (isset($blocksArray[$index])) {
                $blocksArray[$index] = 'L';
            }
        }
        $newBlocks = implode('', $blocksArray);

        // Накапливаем общий выигрыш
        $totalWinAccum += $roundWin;

        $round = [];
        $round[] = [
            'index' => $startIndex,
            'pickaxePhase' => $pickaxePhase,
            'tntPhase' => $tntPhase,
            'type' => 'mine',
        ];
        $round[] = [
            'index' => $startIndex + 1,
            'totalWin' => round($totalWinAccum, 1),
            'type' => 'totalWin',
        ];
        $round['newBlocks'] = $newBlocks;

        ray("<<< generateBonusRound РЕЗУЛЬТАТ",
            "roundWin: $roundWin",
            "totalWinAccum: $totalWinAccum",
            "brokenBlocks:", $pickaxeBroken,
            "newBlocks: $newBlocks");

        return $round;
    }

    /**
     * Находит оптимальную комбинацию: базовый payout + множитель сундука
     * Логика: target = basePayout * chestMultiplier
     *
     * Поддерживает несколько сундуков! Например: 2 сундука по x2 = x4
     *
     * КЛЮЧЕВОЙ ПРИНЦИП: минимизировать количество сломанных блоков!
     */
    private function findOptimalChestPlan(float $target, array $reelPayouts, array $multipliers): array
    {
        // Максимальный payout без сундуков (все блоки depth 0-4)
        $maxPayoutNoChest = 24.5;
        // Максимальный payout со всеми сундуками
        $maxPayoutWithAllChests = array_sum($reelPayouts); // ~129.5

        $candidates = [];

        // === ВАРИАНТ 1: Без сундуков ===
        if ($target <= $maxPayoutNoChest) {
            $candidates[] = [
                'basePayout' => $target,
                'multiplier' => 1,
                'chestReel' => null,
                'chestReels' => [],
                'chestMultipliers' => [],
                'error' => 0,
                'complexity' => $target
            ];
        }

        // === ВАРИАНТ 2: Один сундук с разными множителями ===
        foreach ($multipliers as $mult) {
            if ($mult === 1) continue;

            $neededBase = $target / $mult;

            foreach ($reelPayouts as $reel => $reelPayout) {
                if ($neededBase >= $reelPayout && $neededBase <= $maxPayoutWithAllChests) {
                    // Идеальное совпадение
                    $candidates[] = [
                        'basePayout' => $neededBase,
                        'multiplier' => $mult,
                        'chestReel' => $reel,
                        'chestReels' => [$reel],
                        'chestMultipliers' => [$mult],
                        'error' => 0,
                        'complexity' => $neededBase
                    ];
                } elseif ($neededBase < $reelPayout) {
                    // Base меньше чем payout ряда - используем минимум ряда
                    $actualBase = $reelPayout;
                    $actualResult = $actualBase * $mult;
                    $error = abs($actualResult - $target);
                    // Добавляем только если результат не слишком сильно превышает target (до 50%)
                    if ($actualResult <= $target * 1.5) {
                        $candidates[] = [
                            'basePayout' => $actualBase,
                            'multiplier' => $mult,
                            'chestReel' => $reel,
                            'chestReels' => [$reel],
                            'chestMultipliers' => [$mult],
                            'error' => $error,
                            'complexity' => $actualBase
                        ];
                    }
                }
            }
        }

        // === ВАРИАНТ 3: Несколько сундуков ===
        // Для красивой игры - предпочитаем несколько маленьких множителей
        $multiChestCombos = [
            // 2 сундука
            [2, 2],      // x4
            [2, 3],      // x6
            [3, 3],      // x9
            [2, 5],      // x10
            [2, 6],      // x12
            [3, 5],      // x15
            [2, 10],     // x20
            [3, 6],      // x18
            [5, 5],      // x25
            [3, 10],     // x30
            [5, 6],      // x30
            [5, 10],     // x50
            [6, 10],     // x60
            [10, 10],    // x100
            // 3 сундука
            [2, 2, 2],   // x8
            [2, 2, 3],   // x12
            [2, 3, 3],   // x18
            [2, 2, 5],   // x20
            [3, 3, 3],   // x27
            [2, 3, 5],   // x30
            [2, 2, 10],  // x40
            [3, 3, 5],   // x45
            [2, 5, 5],   // x50
            [3, 3, 6],   // x54
            [2, 5, 6],   // x60
            [3, 5, 5],   // x75
            [2, 5, 10],  // x100
            [5, 5, 5],   // x125
            [3, 5, 10],  // x150
            [5, 5, 6],   // x150
            [5, 5, 10],  // x250
            [5, 6, 10],  // x300
            [6, 6, 10],  // x360
            [5, 10, 10], // x500
            [6, 10, 10], // x600
            [10, 10, 10],// x1000
        ];

        // Генерируем ВСЕ возможные комбинации рядов для каждой комбинации множителей
        $allReelCombos = [
            2 => [[0,1], [0,2], [0,3], [0,4], [1,2], [1,3], [1,4], [2,3], [2,4], [3,4]],
            3 => [[0,1,2], [0,1,3], [0,1,4], [0,2,3], [0,2,4], [0,3,4], [1,2,3], [1,2,4], [1,3,4], [2,3,4]],
            4 => [[0,1,2,3], [0,1,2,4], [0,1,3,4], [0,2,3,4], [1,2,3,4]],
            5 => [[0,1,2,3,4]],
        ];

        // Добавляем 4-х и 5-сундучные комбинации для очень больших целей
        if ($target > 500) {
            $multiChestCombos = array_merge($multiChestCombos, [
                [2, 2, 2, 2],     // x16
                [2, 2, 2, 3],     // x24
                [2, 2, 3, 3],     // x36
                [2, 3, 3, 3],     // x54
                [3, 3, 3, 3],     // x81
                [2, 2, 5, 5],     // x100
                [2, 5, 5, 5],     // x250
                [5, 5, 5, 5],     // x625
                [2, 2, 2, 2, 2],  // x32
                [2, 2, 2, 2, 3],  // x48
                [2, 2, 2, 3, 3],  // x72
                [2, 2, 3, 3, 3],  // x108
                [2, 3, 3, 3, 3],  // x162
                [3, 3, 3, 3, 3],  // x243
            ]);
        }

        foreach ($multiChestCombos as $combo) {
            $totalMult = array_product($combo);
            $neededBase = $target / $totalMult;
            $numChests = count($combo);

            // Получаем все комбинации рядов для этого количества сундуков
            $reelCombos = $allReelCombos[$numChests] ?? [array_slice([0,1,2,3,4], 0, $numChests)];

            foreach ($reelCombos as $reelCombo) {
                // Считаем минимальный payout для этой комбинации рядов
                $minBase = 0;
                foreach ($reelCombo as $r) {
                    $minBase += $reelPayouts[$r];
                }

                if ($neededBase >= $minBase && $neededBase <= $maxPayoutWithAllChests) {
                    $candidates[] = [
                        'basePayout' => $neededBase,
                        'multiplier' => $totalMult,
                        'chestReel' => $reelCombo[0],
                        'chestReels' => $reelCombo,
                        'chestMultipliers' => $combo,
                        'error' => 0,
                        'complexity' => $neededBase,
                        'numChests' => $numChests
                    ];
                } elseif ($neededBase < $minBase && $neededBase > 0) {
                    $actualBase = $minBase;
                    $error = abs($actualBase * $totalMult - $target);
                    if ($error <= $target * 0.5) { // Только если ошибка разумная
                        $candidates[] = [
                            'basePayout' => $actualBase,
                            'multiplier' => $totalMult,
                            'chestReel' => $reelCombo[0],
                            'chestReels' => $reelCombo,
                            'chestMultipliers' => $combo,
                            'error' => $error,
                            'complexity' => $actualBase,
                            'numChests' => $numChests
                        ];
                    }
                }
            }
        }

        // Если нет кандидатов
        if (empty($candidates)) {
            $maxMult = max($multipliers);
            return [
                'basePayout' => $maxPayoutWithAllChests,
                'multiplier' => $maxMult,
                'chestReel' => 2,
                'chestReels' => [0, 1, 2, 3, 4],
                'chestMultipliers' => [$maxMult],
                'error' => abs($target - $maxPayoutWithAllChests * $maxMult)
            ];
        }

        // Сортируем: минимальная ошибка
        usort($candidates, fn($a, $b) => $a['error'] <=> $b['error']);

        // Берём кандидатов с минимальной ошибкой
        $minError = $candidates[0]['error'];
        $goodCandidates = array_filter($candidates, fn($c) => $c['error'] <= $minError + 1);
        $goodCandidates = array_values($goodCandidates);

        // Для МАЛЕНЬКИХ целей (<=100) предпочитаем варианты без сундуков или x2
        // Это даёт лучший контроль точности
        if ($target <= 100) {
            $simpleOptions = array_filter($goodCandidates, fn($c) => $c['multiplier'] <= 2);
            if (!empty($simpleOptions)) {
                $goodCandidates = array_values($simpleOptions);
            }
        }

        // Для БОЛЬШИХ множителей предпочитаем несколько сундуков с меньшим basePayout
        if ($target > 100) {
            // Сортируем по basePayout (меньше = проще достичь)
            usort($goodCandidates, fn($a, $b) => $a['basePayout'] <=> $b['basePayout']);

            // Фильтруем - берём только те, где basePayout достижим (<=100)
            $achievableCandidates = array_filter($goodCandidates, fn($c) => $c['basePayout'] <= 100);

            if (!empty($achievableCandidates)) {
                // Из достижимых предпочитаем с несколькими сундуками
                $multiChestCandidates = array_filter($achievableCandidates, fn($c) => ($c['numChests'] ?? 1) >= 2);
                if (!empty($multiChestCandidates)) {
                    $goodCandidates = array_values($multiChestCandidates);
                } else {
                    $goodCandidates = array_values($achievableCandidates);
                }
            }
        }

        // Группируем по комбинации рядов для рандомизации
        $byReelCombo = [];
        foreach ($goodCandidates as $c) {
            $key = implode(',', $c['chestReels'] ?? []);
            if (!isset($byReelCombo[$key])) {
                $byReelCombo[$key] = $c;
            }
        }

        $options = array_values($byReelCombo);
        shuffle($options);

        $selected = $options[0];

        ray("Выбор плана:", [
            'target' => $target,
            'options' => count($options),
            'allOptions' => array_map(fn($o) => [
                'reels' => $o['chestReels'] ?? [],
                'mults' => $o['chestMultipliers'] ?? [],
                'base' => $o['basePayout'],
                'err' => $o['error']
            ], array_slice($options, 0, 5)),
            'selected' => [
                'reels' => $selected['chestReels'] ?? [],
                'mults' => $selected['chestMultipliers'] ?? [],
                'base' => $selected['basePayout'],
                'error' => $selected['error']
            ]
        ]);

        return $selected;
    }

    /**
     * Умная генерация бонусного раунда под заданный множитель
     * @param float $targetMultiplier - целевой множитель выигрыша
     * @return array - полный state бонусного раунда
     */
    public function generateSmartBonus(float $targetMultiplier): array
    {
        ray("=== ГЕНЕРАЦИЯ УМНОГО БОНУСА ===", "Целевой множитель: $targetMultiplier");

        // Генерируем стандартную карту блоков
        $blocks = $this->generateStandardBlockMap();
        $originalBlocks = $blocks;
        ray("Карта блоков: $blocks");

        // Состояние HP блоков
        $hpState = [];
        $blocksArray = str_split($blocks);
        foreach ($blocksArray as $idx => $block) {
            $hpState[$idx] = $this->blockHP[$block] ?? 1;
        }

        // === УМНАЯ СТРАТЕГИЯ С МНОЖИТЕЛЯМИ СУНДУКОВ ===
        // Сундук даёт МНОЖИТЕЛЬ к базовому payout!
        // Пример: base=30, множитель x6 → итого 180

        // Доступные множители сундуков
        $availableMultipliers = [1, 2, 3, 5, 6, 10];

        // Payout за полный ряд (открытие сундука)
        $reelPayouts = [
            0 => 9.1,   // mithril ряд
            1 => 29.1,  // obsidian ряд
            2 => 31.1,  // obsidian ряд
            3 => 29.1,  // obsidian ряд
            4 => 31.1   // obsidian ряд
        ];

        // Находим оптимальную комбинацию: базовый payout + множитель сундука
        $bestPlan = $this->findOptimalChestPlan($targetMultiplier, $reelPayouts, $availableMultipliers);

        ray("=== ПЛАН ГЕНЕРАЦИИ ===", [
            'target' => $targetMultiplier,
            'basePayout' => $bestPlan['basePayout'],
            'chestMultiplier' => $bestPlan['multiplier'],
            'chestReel' => $bestPlan['chestReel'],
            'result' => $bestPlan['basePayout'] * $bestPlan['multiplier']
        ]);

        $chestsToOpen = $bestPlan['chestReels'] ?? ($bestPlan['chestReel'] !== null ? [$bestPlan['chestReel']] : []);
        $targetBasePayout = $bestPlan['basePayout'];
        $chestMultiplier = $bestPlan['multiplier'];

        // Генерируем boards динамически
        $boards = [];
        $currentPayout = 0.0;

        // Распределяем БАЗОВЫЙ payout по 5 раундам
        $payoutPerRound = $targetBasePayout / 5;

        for ($round = 0; $round < 5; $round++) {
            $board = str_repeat('x', 15);

            // Целевой базовый payout к концу этого раунда
            $targetPayoutThisRound = $payoutPerRound * ($round + 1);
            // Сколько нужно добрать в этом раунде
            $payoutNeededThisRound = max(0, $targetPayoutThisRound - $currentPayout);

            // Проверяем достигнут ли БАЗОВЫЙ target НА НАЧАЛО раунда
            $targetReachedAtStart = ($currentPayout >= $targetBasePayout);
            $minReelsToProcess = 1; // Гарантируем минимум 1 ряд в каждом раунде

            ray("Round $round: currentPayout=$currentPayout, targetBase=$targetBasePayout, needed=$payoutNeededThisRound, chests=" . json_encode($chestsToOpen));

            // Для первого раунда добавляем бонусные символы
            if ($round === 0) {
                $bonusPositions = [1, 5, 11];
                foreach ($bonusPositions as $pos) {
                    $board[$pos] = 's';
                }
            }

            // Отслеживаем payout набранный в этом раунде
            $roundPayout = 0.0;
            $reelsProcessed = 0;

            // Порядок рядов: сундучные ряды первыми, остальные в случайном порядке
            $reelOrder = [];
            foreach ($chestsToOpen as $chest) {
                $reelOrder[] = $chest;
            }
            // Остальные ряды в случайном порядке
            $otherReels = [];
            foreach ([0, 1, 2, 3, 4] as $r) {
                if (!in_array($r, $reelOrder)) {
                    $otherReels[] = $r;
                }
            }
            shuffle($otherReels); // Рандомизируем порядок для разнообразия
            $reelOrder = array_merge($reelOrder, $otherReels);

            // Проверяем есть ли ещё несломанные сундучные ряды
            $unbrokenChestReels = [];
            foreach ($chestsToOpen as $chestReel) {
                $startDepth = -1;
                for ($d = 0; $d < 6; $d++) {
                    $idx = $chestReel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $startDepth = $d;
                        break;
                    }
                }
                if ($startDepth >= 0) {
                    $unbrokenChestReels[] = $chestReel;
                }
            }

            // Если есть несломанные сундучные ряды - обязательно ломаем их, игнорируя targetBasePayout
            $mustBreakChests = !empty($unbrokenChestReels);

            // Если target уже достигнут И все сундуки открыты - добавляем визуальную активность
            if ($currentPayout >= $targetBasePayout * 0.98 && !$mustBreakChests) {
                // Находим ряды с несломанными блоками
                $activeReels = [];
                for ($r = 0; $r < 5; $r++) {
                    for ($d = 0; $d < 6; $d++) {
                        $idx = $r * 6 + $d;
                        if ($hpState[$idx] > 0) {
                            $activeReels[] = $r;
                            break;
                        }
                    }
                }

                if (!empty($activeReels)) {
                    // Перемешиваем ряды для случайного выбора
                    shuffle($activeReels);

                    // Распределяем 2-3 кирки по РАЗНЫМ рядам
                    $pickaxesToPlace = min(3, count($activeReels));
                    for ($i = 0; $i < $pickaxesToPlace; $i++) {
                        $targetReel = $activeReels[$i];
                        $boardStart = $targetReel * 3;

                        // Добавляем 1 кирку в случайную позицию этого ряда
                        $cols = [0, 1, 2];
                        shuffle($cols);
                        foreach ($cols as $col) {
                            if ($board[$boardStart + $col] === 'x') {
                                $board[$boardStart + $col] = '5';
                                break;
                            }
                        }
                    }
                } else {
                    // Все блоки сломаны - добавляем декоративные кирки в случайные позиции
                    // Они будут анимированы, но ничего не сломают
                    $allPositions = range(0, 14);
                    shuffle($allPositions);
                    $decorativeCount = min(3, count($allPositions));
                    for ($i = 0; $i < $decorativeCount; $i++) {
                        $pos = $allPositions[$i];
                        if ($board[$pos] === 'x') {
                            $board[$pos] = '5';
                        }
                    }
                }

                $boards[] = $board;
                ray("SIM Round $round (target reached): board=$board");
                continue; // Переходим к следующему раунду
            }

            // === РАВНОМЕРНОЕ РАСПРЕДЕЛЕНИЕ КИРОК ПО РАУНДАМ ===
            // Ограничиваем количество кирок за раунд для реалистичности
            // Для больших целей нужно больше кирок
            $basePickaxes = ($targetBasePayout > 50) ? 6 : 5;
            $maxPickaxesPerRound = $basePickaxes + $round; // 5-6 в первом раунде, до 9-10 в последнем
            $totalPickaxesThisRound = 0;

            // Распределяем урон по нескольким рядам (2-4 ряда за раунд)
            $reelsToProcessThisRound = count($chestsToOpen) > 1 ? min(4, count($reelOrder)) : min(3, count($reelOrder));

            // Для сундучных рядов распределяем ломание равномерно
            // Считаем сколько осталось раундов и сколько HP осталось в сундучных рядах
            $remainingRounds = 5 - $round;

            // Для каждого ряда генерируем кирки
            foreach ($reelOrder as $reel) {
                // Если достигли общей цели - стоп (кроме несломанных сундуков)
                $reachedOverallTarget = ($currentPayout + $roundPayout) >= $targetBasePayout * 0.95;
                $isChestReel = in_array($reel, $chestsToOpen);

                if ($reachedOverallTarget && !$isChestReel) {
                    break;
                }

                // Ограничиваем количество рядов
                if ($reelsProcessed >= $reelsToProcessThisRound && $reachedOverallTarget) {
                    break;
                }

                // Ограничиваем общее количество кирок
                if ($totalPickaxesThisRound >= $maxPickaxesPerRound) {
                    break;
                }

                // Находим первый блок с HP > 0
                $startDepth = -1;
                for ($d = 0; $d < 6; $d++) {
                    $idx = $reel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $startDepth = $d;
                        break;
                    }
                }

                if ($startDepth < 0) {
                    continue; // Ряд полностью сломан
                }

                // Проверяем запланирован ли сундук для этого ряда
                $reelHasPlannedChest = in_array($reel, $chestsToOpen);

                // Если мы уже у последнего блока (startDepth=5) - это сундук
                // Открываем только если запланирован
                if ($startDepth == 5) {
                    if (!$reelHasPlannedChest) {
                        continue;
                    }
                }

                // Если ряд не сундучный, ограничиваем depth до 5 (не открываем сундук)
                // НО! Если мы ещё не достигли target, нужно ломать блоки из обычных рядов
                $needMorePayout = ($currentPayout + $roundPayout) < $targetBasePayout * 0.95;

                // Сколько слотов доступно для кирок
                $boardStart = $reel * 3;
                $availablePositions = [];
                for ($col = 0; $col < 3; $col++) {
                    if ($board[$boardStart + $col] === 'x') {
                        $availablePositions[] = $col;
                    }
                }

                if (empty($availablePositions)) {
                    continue;
                }

                // Ограничиваем слоты исходя из оставшихся кирок
                $maxSlotsThisReel = min(count($availablePositions), $maxPickaxesPerRound - $totalPickaxesThisRound);
                if ($maxSlotsThisReel <= 0) {
                    continue;
                }

                // === РАВНОМЕРНОЕ РАСПРЕДЕЛЕНИЕ ДЛЯ СУНДУЧНЫХ РЯДОВ ===
                $canOpenChest = in_array($reel, $chestsToOpen);
                $maxDepth = $canOpenChest ? 6 : 5;

                // Считаем общий HP в этом ряду
                $totalReelHP = 0;
                for ($d = $startDepth; $d < $maxDepth; $d++) {
                    $idx = $reel * 6 + $d;
                    $totalReelHP += $hpState[$idx];
                }

                // Для сундучных рядов - распределяем HP равномерно по оставшимся раундам
                if ($canOpenChest && $remainingRounds > 1) {
                    // Сколько HP ломать в этом раунде (равномерно, но гарантируем прогресс)
                    $hpPerRound = max(3, ceil($totalReelHP / $remainingRounds));
                    // Но не больше чем можем с доступными слотами
                    $hpPerRound = min($hpPerRound, $maxSlotsThisReel * 5);
                } else {
                    // Для последнего раунда или обычных рядов - ломаем всё оставшееся
                    $hpPerRound = $totalReelHP;
                }

                $hpToBreak = 0;
                $expectedPayout = 0;

                // Сколько ещё нужно до target
                $remainingToTarget = max(0, $targetBasePayout - $currentPayout - $roundPayout);

                for ($d = $startDepth; $d < $maxDepth; $d++) {
                    $idx = $reel * 6 + $d;
                    $blockHP = $hpState[$idx];
                    $blockPayout = $this->blockPayout[$blocksArray[$idx]] ?? 0;

                    // Для сундучных рядов - ломаем до лимита раунда
                    if ($canOpenChest) {
                        if ($hpToBreak + $blockHP <= $hpPerRound || $hpToBreak == 0) {
                            $hpToBreak += $blockHP;
                            $expectedPayout += $blockPayout;
                        } else {
                            break;
                        }
                        continue;
                    }

                    // Для обычных рядов - строго контролируем payout
                    $currentProjected = $currentPayout + $roundPayout + $expectedPayout;

                    // Если уже достигли target - полный стоп
                    if ($currentProjected >= $targetBasePayout * 0.95) {
                        break;
                    }

                    // Если этот блок превысит target - стоп
                    $projectedAfter = $currentProjected + $blockPayout;
                    if ($projectedAfter > $targetBasePayout * 1.05 && $hpToBreak > 0) {
                        break;
                    }

                    // Добавляем блок
                    $hpToBreak += $blockHP;
                    $expectedPayout += $blockPayout;
                }

                // Гарантируем минимум 1 блок в первом ряду раунда
                if ($reelsProcessed == 0 && $hpToBreak == 0) {
                    $idx = $reel * 6 + $startDepth;
                    $hpToBreak = $hpState[$idx];
                }

                // Если нужно больше payout и есть несломанные блоки - гарантируем прогресс
                if ($needMorePayout && $hpToBreak == 0 && $startDepth < 5) {
                    $idx = $reel * 6 + $startDepth;
                    $hpToBreak = $hpState[$idx];
                }

                if ($hpToBreak == 0) {
                    continue;
                }

                // Максимальный урон с доступных слотов
                $maxDamageThisReel = $maxSlotsThisReel * 5;
                $damageThisRound = min($hpToBreak, $maxDamageThisReel);

                // Подбираем кирки
                $pickaxes = $this->selectPickaxesForDamage($damageThisRound);
                while (count($pickaxes) > $maxSlotsThisReel) {
                    array_pop($pickaxes);
                }

                $totalPickaxesThisRound += count($pickaxes);

                shuffle($availablePositions);
                foreach ($pickaxes as $i => $pickaxe) {
                    if (isset($availablePositions[$i])) {
                        $board[$boardStart + $availablePositions[$i]] = $pickaxe;
                    }
                }

                // Симулируем урон
                $pickaxeDamage = ['2' => 5, '3' => 3, '4' => 2, '5' => 1];
                $totalDamage = 0;
                foreach ($pickaxes as $p) {
                    $totalDamage += $pickaxeDamage[$p] ?? 0;
                }

                for ($d = $startDepth; $d < 6 && $totalDamage > 0; $d++) {
                    $idx = $reel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $dmg = min($hpState[$idx], $totalDamage);
                        $hpState[$idx] -= $dmg;
                        $totalDamage -= $dmg;

                        if ($hpState[$idx] <= 0) {
                            $block = $blocksArray[$idx];
                            $payout = $this->blockPayout[$block] ?? 0;
                            $currentPayout += $payout;
                            $roundPayout += $payout;
                        }
                    }
                }

                $reelsProcessed++;
            }

            ray("Round $round finished: roundPayout=$roundPayout, totalPayout=$currentPayout, reelsProcessed=$reelsProcessed");

            // Гарантируем что board не пустой - добавляем минимум 2 кирки если нет
            $pickaxeCount = preg_match_all('/[2345]/', $board);
            if ($pickaxeCount < 2) {
                // Находим свободные позиции и добавляем декоративные кирки
                for ($pos = 0; $pos < 15 && $pickaxeCount < 2; $pos++) {
                    if ($board[$pos] === 'x') {
                        $board[$pos] = '5';
                        $pickaxeCount++;
                    }
                }
                ray("Добавлены декоративные кирки: board=$board");
            }

            $boards[] = $board;
            ray("SIM Round $round: board=$board, basePayout=$currentPayout / $targetBasePayout");
        }

        // Если не достигли target после 5 раундов - добавляем ещё кирки в последний раунд
        if ($currentPayout < $targetBasePayout * 0.90 && count($boards) == 5) {
            ray("WARNING: Не достигли target! currentPayout=$currentPayout, target=$targetBasePayout. Добавляем кирки.");

            // Находим несломанные ряды и добавляем кирки
            $lastBoard = str_split($boards[4]);
            for ($r = 0; $r < 5; $r++) {
                if (in_array($r, $chestsToOpen)) continue; // Сундучные ряды уже обработаны

                $startDepth = -1;
                for ($d = 0; $d < 5; $d++) { // До depth=4, без сундука
                    $idx = $r * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $startDepth = $d;
                        break;
                    }
                }

                if ($startDepth >= 0) {
                    $boardStart = $r * 3;
                    for ($col = 0; $col < 3; $col++) {
                        if ($lastBoard[$boardStart + $col] === 'x') {
                            $lastBoard[$boardStart + $col] = '3'; // Добавляем кирку
                            break;
                        }
                    }
                }
            }
            $boards[4] = implode('', $lastBoard);
        }

        ray("Сгенерировано boards: " . count($boards), "Итоговый payout: $currentPayout");

        // Собираем state
        $state = [];
        $state[] = [
            'index' => 0,
            's' => $this->generateSeed(),
            'type' => 's',
        ];

        $state[] = [
            'anticipation' => 3,
            'blocks' => $originalBlocks,
            'board' => $boards[0],
            'index' => 1,
            'type' => 'reveal',
        ];

        // Позиции бонусных символов
        $bonusPositions = [];
        $boardChars = str_split($boards[0]);
        foreach ($boardChars as $idx => $char) {
            if ($char === 's') {
                $reel = intdiv($idx, 3);
                $col = $idx % 3;
                $bonusPositions[] = [$reel, $col];
            }
        }

        $state[] = [
            'bonusType' => 'Bonus',
            'freeSpinCount' => 5,
            'index' => 2,
            'positions' => $bonusPositions,
            'type' => 'bonusEnter',
        ];

        // Сброс состояния для реального выполнения
        $blocks = $originalBlocks;
        $hpStateReal = [];
        $totalWin = 0.0;
        $currentIndex = 3;

        // Генерируем 5 раундов бонуса
        for ($roundNum = 0; $roundNum < 5; $roundNum++) {
            $round = $this->generateBonusRound($blocks, $boards[$roundNum], $currentIndex, $hpStateReal, $totalWin);
            $blocks = $round['newBlocks'];
            unset($round['newBlocks']);

            foreach ($round as $item) {
                $state[] = $item;
            }
            $currentIndex += 2;

            // Добавляем bonusReveal для следующего раунда (кроме последнего)
            if ($roundNum < 4) {
                $state[] = [
                    'anticipation' => 0,
                    'board' => $boards[$roundNum + 1],
                    'freeSpinsRemaining' => 4 - $roundNum,
                    'index' => $currentIndex,
                    'type' => 'bonusReveal',
                ];
                $currentIndex++;
            }
        }

        // Применяем множители сундуков
        $multipliers = $bestPlan['chestMultipliers'] ?? [];
        if (empty($multipliers) && $chestMultiplier > 1) {
            $multipliers = [$chestMultiplier];
        }

        $finalWin = $totalWin * $chestMultiplier;

        ray("FinalWin: baseWin=$totalWin, chestMultiplier=$chestMultiplier, finalWin=$finalWin");

        $state[] = [
            'baseWinAmount' => $totalWin,
            'finalWin' => $finalWin,
            'index' => $currentIndex,
            'multipliers' => $multipliers,
            'type' => 'finalWin',
        ];
        $currentIndex++;

        $state[] = [
            'index' => $currentIndex,
            'totalSpins' => 5,
            'totalWin' => $finalWin,
            'type' => 'bonusExit',
        ];

        return $state;
    }

    /**
     * Генерирует стандартную карту блоков
     * Структура: первые 2 блока - земля, потом камень, руда, золото/алмаз, обсидиан
     */
    private function generateStandardBlockMap(): string
    {
        $blocks = '';
        $patterns = [
            'ddcrgm', // ряд 0
            'ddcrgo', // ряд 1
            'ddcrmo', // ряд 2
            'ddcrgo', // ряд 3
            'ddcrmo', // ряд 4
        ];

        foreach ($patterns as $pattern) {
            $blocks .= $pattern;
        }

        return $blocks;
    }

    /**
     * Планирует разрушение блоков для достижения целевого множителя
     * @param string $blocks - карта блоков
     * @param float $targetMultiplier - целевой множитель
     * @return array - план разрушения [раунд => [индексы блоков для разрушения]]
     */
    /**
     * Планирует разрушение блоков для достижения целевого множителя
     * Упрощенная версия: без сундуков, просто ломаем блоки до достижения цели
     * Учитывает лимит урона 15/раунд на ряд
     *
     * @return array ['plan' => [...], 'chests' => [...], 'basePayout' => float, 'finalPayout' => float]
     */
    private function planDestruction(string $blocks, float $targetMultiplier): array
    {
        $blocksArray = str_split($blocks);

        // Информация о блоках
        $blockInfo = [];
        foreach ($blocksArray as $idx => $block) {
            $blockInfo[$idx] = [
                'block' => $block,
                'payout' => $this->blockPayout[$block] ?? 0,
                'hp' => $this->blockHP[$block] ?? 1,
                'reel' => intdiv($idx, 6),
                'depth' => $idx % 6,
            ];
        }

        // Максимальный урон за раунд (3 кирки * 5)
        $maxDamagePerRound = 15;

        // Состояние HP блоков
        $hpState = [];
        foreach ($blockInfo as $idx => $info) {
            $hpState[$idx] = $info['hp'];
        }

        $plan = [[], [], [], [], []]; // 5 раундов
        $currentPayout = 0.0;
        $targetPayout = $targetMultiplier;

        ray("=== ПЛАНИРОВАНИЕ ===", "Target: $targetPayout", "Max damage/round: $maxDamagePerRound");

        // Для каждого раунда выбираем какие блоки ломать
        for ($round = 0; $round < 5 && $currentPayout < $targetPayout; $round++) {
            ray("--- Раунд $round ---", "currentPayout: $currentPayout / $targetPayout");

            // Для каждого ряда считаем сколько можем сломать за этот раунд
            for ($reel = 0; $reel < 5; $reel++) {
                if ($currentPayout >= $targetPayout) {
                    break;
                }

                // Находим первый несломанный блок
                $startDepth = -1;
                for ($d = 0; $d < 6; $d++) {
                    $idx = $reel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $startDepth = $d;
                        break;
                    }
                }

                if ($startDepth < 0) {
                    continue; // Ряд полностью сломан
                }

                // Симулируем нанесение урона этому ряду
                $damageRemaining = $maxDamagePerRound;
                $brokenThisRound = [];
                $payoutThisRound = 0;

                for ($d = $startDepth; $d < 6 && $damageRemaining > 0; $d++) {
                    $idx = $reel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $dmg = min($hpState[$idx], $damageRemaining);
                        $hpState[$idx] -= $dmg;
                        $damageRemaining -= $dmg;

                        if ($hpState[$idx] <= 0) {
                            $brokenThisRound[] = $idx;
                            $payoutThisRound += $blockInfo[$idx]['payout'];
                        }
                    }
                }

                // Добавляем сломанные блоки в план
                foreach ($brokenThisRound as $idx) {
                    $plan[$round][] = $idx;
                }
                $currentPayout += $payoutThisRound;

                ray("Reel $reel: broken=" . implode(',', $brokenThisRound) . ", payout=+$payoutThisRound, total=$currentPayout");
            }
        }

        ray("=== ПЛАН ГОТОВ ===", "План:", $plan, "Итоговый payout: $currentPayout");

        return [
            'plan' => $plan,
            'chests' => [],
            'basePayout' => $currentPayout,
            'finalPayout' => $currentPayout,
        ];
    }

    /**
     * Генерирует доски (кирки) для каждого раунда на основе плана разрушения
     * @param string $blocks - карта блоков
     * @param array $plan - план разрушения
     * @return array - массив из 5 досок
     */
    private function generateBoardsFromPlan(string $blocks, array $plan): array
    {
        $boards = [];
        $blocksArray = str_split($blocks);
        $hpState = []; // Текущее HP блоков (остаточное)
        $brokenBlocks = []; // Уже сломанные блоки

        // Инициализируем HP
        foreach ($blocksArray as $idx => $block) {
            $hpState[$idx] = $this->blockHP[$block] ?? 1;
        }

        ray("=== Генерация досок ===", "Blocks: $blocks");

        for ($roundNum = 0; $roundNum < 5; $roundNum++) {
            $targetBlocks = $plan[$roundNum] ?? [];
            $board = str_repeat('x', 15);

            ray("--- Раунд $roundNum ---", "Целевые блоки:", $targetBlocks);

            // Для первого раунда добавляем 3 бонусных символа
            if ($roundNum === 0) {
                $bonusPositions = [1, 5, 11];
                foreach ($bonusPositions as $pos) {
                    $board[$pos] = 's';
                }
            }

            // Группируем целевые блоки по рядам
            $reelTargets = [];
            foreach ($targetBlocks as $blockIdx) {
                $reel = intdiv($blockIdx, 6);
                $depth = $blockIdx % 6;
                if (!isset($reelTargets[$reel])) {
                    $reelTargets[$reel] = ['maxDepth' => $depth, 'blocks' => [$blockIdx]];
                } else {
                    $reelTargets[$reel]['maxDepth'] = max($reelTargets[$reel]['maxDepth'], $depth);
                    $reelTargets[$reel]['blocks'][] = $blockIdx;
                }
            }

            // Для каждого ряда генерируем кирки
            foreach ($reelTargets as $reel => $info) {
                $maxTargetDepth = $info['maxDepth'];

                // Находим первый блок с HP > 0
                $startDepth = 0;
                for ($d = 0; $d < 6; $d++) {
                    $idx = $reel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $startDepth = $d;
                        break;
                    }
                }

                // Считаем HP блоков от startDepth до maxTargetDepth (остаточный HP)
                $neededDamage = 0;
                for ($d = $startDepth; $d <= $maxTargetDepth; $d++) {
                    $idx = $reel * 6 + $d;
                    $neededDamage += $hpState[$idx];
                }

                // Подбираем кирки (макс 15 урона)
                $pickaxes = $this->selectPickaxesForDamage($neededDamage);

                ray("Reel $reel: startDepth=$startDepth, maxDepth=$maxTargetDepth, neededDamage=$neededDamage, pickaxes=", $pickaxes);

                // Размещаем кирки РАНДОМНО
                $boardStart = $reel * 3;
                $availablePositions = [];
                for ($col = 0; $col < 3; $col++) {
                    if ($board[$boardStart + $col] === 'x') {
                        $availablePositions[] = $col;
                    }
                }
                shuffle($availablePositions);

                foreach ($pickaxes as $pickaxeIdx => $pickaxe) {
                    if (isset($availablePositions[$pickaxeIdx])) {
                        $col = $availablePositions[$pickaxeIdx];
                        $board[$boardStart + $col] = $pickaxe;
                    }
                }

                // Симулируем урон (обновляем hpState)
                $pickaxeDamage = ['2' => 5, '3' => 3, '4' => 2, '5' => 1];
                $totalDamage = 0;
                foreach ($pickaxes as $p) {
                    $totalDamage += $pickaxeDamage[$p] ?? 0;
                }

                for ($d = $startDepth; $d <= 5 && $totalDamage > 0; $d++) {
                    $idx = $reel * 6 + $d;
                    if ($hpState[$idx] > 0) {
                        $dmg = min($hpState[$idx], $totalDamage);
                        $hpState[$idx] -= $dmg;
                        $totalDamage -= $dmg;
                        if ($hpState[$idx] <= 0) {
                            $brokenBlocks[] = $idx;
                        }
                    }
                }
            }

            ray("Board раунда $roundNum: $board");
            $boards[] = $board;
        }

        ray("=== Все доски ===", $boards);

        return $boards;
    }

    /**
     * Подбирает оптимальные кирки для заданного урона
     * @param int $damage - необходимый урон
     * @return array - массив символов кирок
     */
    private function selectPickaxesForDamage(int $damage): array
    {
        if ($damage <= 0) {
            return [];
        }

        $pickaxes = [];
        $remaining = $damage;

        // Приоритет кирок: от слабых к сильным (чтобы не переломать лишнего)
        // 5=1, 4=2, 3=3, 2=5
        $pickaxeTypes = [
            '2' => 5,
            '3' => 3,
            '4' => 2,
            '5' => 1,
        ];

        // Сначала пробуем подобрать точно
        // Используем жадный алгоритм от больших к меньшим
        foreach ($pickaxeTypes as $symbol => $dmg) {
            while ($remaining >= $dmg && count($pickaxes) < 3) {
                $pickaxes[] = $symbol;
                $remaining -= $dmg;
            }
        }

        // Если не хватило кирок, добавляем самые слабые
        while ($remaining > 0 && count($pickaxes) < 3) {
            $pickaxes[] = '5';
            $remaining -= 1;
        }

        return $pickaxes;
    }

    public function getBonusState(): array
    {
        $blocks = 'dccrmmdccrgoddcrmodccrggdccrgo';
        $board = '5xxxsxx5s4xx4xs';
        $state = [];
        $state[] = [
            'index' => 0,
            's' => $this->generateSeed(),
            'type' => 's',
        ];
        $state[] = [
            'anticipation' => 2,
            'blocks' => $blocks,
            'board' => $board,
            'index' => 1,
            'type' => 'reveal',
        ];
        $state[] = [
            'bonusType' => 'Bonus',
            'freeSpinCount' => 5,
            'index' => 2,
            'positions' => [
                [
                    0,
                    1,
                ],
                [
                    1,
                    0,
                ],
                [
                    3,
                    0,
                ],
            ],
            'type' => 'bonusEnter',
        ];
        $hpState = []; // Состояние HP блоков между раундами
        $totalWin = 0.0; // Накопленный выигрыш (множитель)
        $round = $this->generateBonusRound($blocks, '5xxxsxx5s4xx4xs', 3, $hpState, $totalWin);
        $blocks = $round['newBlocks']; // Обновляем блоки с L для следующего раунда
        unset($round['newBlocks']); // Удаляем из массива, чтобы не попало в state
        foreach ($round as $item) {
            $state[] = $item;
        }

        $state[] = [
            'anticipation' => 0,
            'board' => 'xxxxxx55555xx55',
            'freeSpinsRemaining' => 3,
            'index' => 5,
            'type' => 'bonusReveal',
        ];
        $round = $this->generateBonusRound($blocks, 'xxxxxx55555xx55', 6, $hpState, $totalWin);
        $blocks = $round['newBlocks']; // Обновляем блоки с L для следующего раунда
        unset($round['newBlocks']); // Удаляем из массива, чтобы не попало в state
        foreach ($round as $item) {
            $state[] = $item;
        }
        $state[] = [
            'anticipation' => 0,
            'board' => '5xxx4xxxx5xxx4x',
            'freeSpinsRemaining' => 2,
            'index' => 8,
            'type' => 'bonusReveal',
        ];
        $round = $this->generateBonusRound($blocks, '5xxx4xxxx5xxx4x', 9, $hpState, $totalWin);
        $blocks = $round['newBlocks']; // Обновляем блоки с L для следующего раунда
        unset($round['newBlocks']); // Удаляем из массива, чтобы не попало в state
        foreach ($round as $item) {
            $state[] = $item;
        }
        $state[] = [
            'anticipation' => 0,
            'board' => 'x5555xxx5xx55xx',
            'freeSpinsRemaining' => 1,
            'index' => 12,
            'type' => 'bonusReveal',
        ];
        $round = $this->generateBonusRound($blocks, 'x5555xxx5xx55xx', 13, $hpState, $totalWin);
        $blocks = $round['newBlocks']; // Обновляем блоки с L для следующего раунда
        unset($round['newBlocks']); // Удаляем из массива, чтобы не попало в state
        foreach ($round as $item) {
            $state[] = $item;
        }
        $state[] = [
            'anticipation' => 0,
            'board' => 'xxx55xxx5xx53xx',
            'freeSpinsRemaining' => 0,
            'index' => 15,
            'type' => 'bonusReveal',
        ];
        $round = $this->generateBonusRound($blocks, 'xxx55xxx5xx53xx', 16, $hpState, $totalWin);
        $blocks = $round['newBlocks']; // Обновляем блоки с L для следующего раунда
        unset($round['newBlocks']); // Удаляем из массива, чтобы не попало в state
        foreach ($round as $item) {
            $state[] = $item;
        }

        $state[] = [
            'baseWinAmount' => $totalWin,
            'finalWin' => $totalWin,
            'index' => 19,
            'multipliers' => [],
            'type' => 'finalWin',
        ];
        $state[] = [
            'index' => 20,
            'totalSpins' => 5,
            'totalWin' => $totalWin,
            'type' => 'bonusExit',
        ];
        return $state;

    }
    public function getBonusRound(?float $targetMultiplier = null): array
    {
        // Если указан целевой множитель, используем умную генерацию
        if ($targetMultiplier !== null) {
            $state = $this->generateSmartBonus($targetMultiplier);
        } else {
            $state = $this->getBonusState();
        }

        // Получаем итоговый множитель из state
        // Выигрыш начисляется отдельным запросом после подтверждения результата
        $finalWin = 0;
        foreach ($state as $item) {
            if (isset($item['type']) && $item['type'] === 'finalWin') {
                $finalWin = $item['finalWin'] ?? 0;
                break;
            }
        }

        $array = [
                'balance' => [
                'amount' => $this->session->balance,
                'currency' => 'RUB',
                ],
                'round' => [
                'betID' => $this->generateSeed(),
                'amount' => $this->bet,
                'payout' => (int) round($this->bet * $finalWin),
                'payoutMultiplier' => $finalWin,
                    'active' => true,
                'state' => $state,
                    'mode' => 'BONUS',
                    'event' => null,
                ],
            ];
            $this->session->balance += $this->bet * $finalWin;
            $this->session->save();
        return $array;
    }

    public function playBonus(?float $targetMultiplier = null): array
    {
        return $this->getBonusRound($targetMultiplier);
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

        $bonusCount = substr_count($board, 's');
        $isBonusTriggered = $bonusCount >= 3;

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

        // Если выпал бонус - генерируем бонусный раунд
        if ($isBonusTriggered) {
            // Определяем целевой множитель для бонуса
            $bonusTargetMultiplier = $this->multiplierSeed !== null
                ? (float) $this->multiplierSeed
                : $this->generateRandomBonusMultiplier();

            // Генерируем умный бонусный раунд
            $bonusState = $this->generateSmartBonus($bonusTargetMultiplier);

            // Получаем финальный выигрыш из бонуса
            $bonusFinalWin = 0.0;
            foreach ($bonusState as $item) {
                if ($item['type'] === 'finalWin') {
                    $bonusFinalWin = $item['finalWin'];
                    break;
                }
            }

            return [
                'balance' => [
                    'amount' => $this->session->balance,
                    'currency' => 'RUB',
                ],
                'round' => [
                    'betID' => $seed,
                    'amount' => $this->bet,
                    'payout' => (int) round($this->bet * $bonusFinalWin),
                    'payoutMultiplier' => $bonusFinalWin,
                    'active' => true,
                    'state' => $bonusState,
                    'mode' => 'BONUS',
                    'event' => 'TRIGGER_BONUS',
                ],
            ];
        }

        // Обычный раунд (без бонуса)
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
                'active' => $totalWin > 0,
                'state' => [
                    [
                        'index' => 0,
                        's' => $seed,
                        'type' => 's',
                    ],
                    [
                        'index' => 1,
                        'anticipation' => $bonusCount,
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
                'event' => null,
            ],
        ];
        $this->session->balance += $this->bet * $totalWin;
        $this->session->save();
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

        return $round;
    }

    /**
     * Генерирует случайный множитель для бонусного раунда
     * Распределение весов для разных диапазонов множителей
     */
    private function generateRandomBonusMultiplier(): float
    {
        $rand = mt_rand(1, 1000);

        // Распределение вероятностей:
        // 50% - 10-30x (частые, небольшие выигрыши)
        // 30% - 30-80x (средние выигрыши)
        // 15% - 80-200x (хорошие выигрыши)
        // 4% - 200-500x (большие выигрыши)
        // 1% - 500-1000x (редкие, крупные выигрыши)

        if ($rand <= 500) {
            return mt_rand(100, 300) / 10; // 10.0 - 30.0
        } elseif ($rand <= 800) {
            return mt_rand(300, 800) / 10; // 30.0 - 80.0
        } elseif ($rand <= 950) {
            return mt_rand(800, 2000) / 10; // 80.0 - 200.0
        } elseif ($rand <= 990) {
            return mt_rand(2000, 5000) / 10; // 200.0 - 500.0
        } else {
            return mt_rand(5000, 10000) / 10; // 500.0 - 1000.0
        }
    }

    private function calculateWin(array $pickaxePhase, array $tntPhase = []): float
    {
        $totalWin = 0.0;

        foreach ($pickaxePhase as $reel) {
            if (!isset($reel['pickaxes']) || !is_array($reel['pickaxes'])) {
                continue;
            }
            foreach ($reel['pickaxes'] as $pickaxe) {
                if (isset($pickaxe['payout']) && !empty($pickaxe['is_broken'])) {
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
                    'x' => (int) (30 * (1 - $boostFactor)),
                    '1' => (int) (10 * (1 + $boostFactor * 1.5)), // TNT
                    '2' => (int) (15 * (1 + $boostFactor * 1.5)), // Мощная кирка (5 урона)
                    '3' => (int) (15 * (1 + $boostFactor * 0.5)), // Кирка (3 урона)
                    '4' => (int) (15 * (1 - $boostFactor * 0.3)), // Кирка (2 урона)
                    '5' => (int) (10 * (1 - $boostFactor * 0.5)), // Кирка (1 урон)
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
        $emptyWeight = (int) (40 * (1 - $strength));       // 40 -> 8 при strength 0.8
        $tntWeight = (int) (5 + 10 * $strength);         // 5 -> 13
        $strongWeight = (int) (5 + 15 * $strength);         // 5 -> 17 (5 урона)
        $mediumWeight = (int) (10 + 10 * $strength);        // 10 -> 18 (3 урона)
        $weakWeight = (int) (15 * (1 - $strength * 0.5)); // 15 -> 9 (2 урона)
        $veryWeakWeight = (int) (10 * (1 - $strength));       // 10 -> 2 (1 урон)

        // Если есть целевой множитель, корректируем
        if ($targetMultiplier !== null && $targetMultiplier > 20) {
            $boostFactor = min(($targetMultiplier / 150), 0.5);
            $emptyWeight = (int) ($emptyWeight * (1 - $boostFactor));
            $tntWeight = (int) ($tntWeight * (1 + $boostFactor));
            $strongWeight = (int) ($strongWeight * (1 + $boostFactor));
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
        $bonusCount = mt_rand(3, 3);
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

    private function mine(string $blocks, string $board, array &$hpState = []): array
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

            $currentIndex = 0; // накопительное разрушение слева направо
            $allBroken = [];
            $pickaxesOut = [];

            // Track remaining HP for each block (cumulative damage)
            $blockHPRemaining = [];

            // Помечаем блоки L как уже сломанные (HP = 0), но НЕ добавляем в brokenBlocks
            // т.к. они были сломаны в предыдущих раундах
            foreach ($rowBlocks as $idx => $block) {
                if ($block === 'L') {
                    $blockHPRemaining[$idx] = 0;
                }
            }

            foreach ($pickaxes as $pickaxe) {
                if($pickaxe['symbol'] === 's') {
                    continue;
                }
                $damage = $pickaxe['damage'];

                $localBroken = [];
                $localPayout = 0.0;
                $is_broken = false;
                $lastBlock = null;
                $lastHP = null;
                $lastBlockIndex = null;

                while ($currentIndex < 6 && $damage > 0) {
                    $block = $rowBlocks[$currentIndex];

                    // Пропускаем блоки L (уже сломанные) - они уже добавлены в allBroken
                    if ($block === 'L') {
                        $currentIndex++;
                        continue;
                    }

                    $baseHP = $this->blockHP[$block] ?? null;
                    if ($baseHP === null) {
                        break;
                    }

                    $globalIndex = $reel * 6 + $currentIndex;

                    // Get remaining HP for this block (accounting for previous damage from hpState or current round)
                    if (!isset($blockHPRemaining[$currentIndex])) {
                        // Сначала проверяем сохраненное состояние HP между раундами
                        if (isset($hpState[$globalIndex])) {
                            $blockHPRemaining[$currentIndex] = $hpState[$globalIndex];
                        } else {
                        $blockHPRemaining[$currentIndex] = $baseHP;
                        }
                    }
                    $hp = $blockHPRemaining[$currentIndex];

                    // Skip if block is already broken (HP <= 0)
                    if ($hp <= 0) {
                        $currentIndex++;
                        continue;
                    }

                    // Deal 1 damage per hit
                    $hp--;
                    $damage--;
                    $blockHPRemaining[$currentIndex] = $hp;
                    $hpState[$globalIndex] = $hp; // Сохраняем HP для следующих раундов

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
                if ($landingDepth > 0) {
                    $neighbors[] = $reel * 6 + ($landingDepth - 1);
                }

                if ($landingDepth < 5) {
                    $neighbors[] = $reel * 6 + ($landingDepth + 1);
                }

                // соседние столбцы
                if ($reel > 0) {
                    if ($landingDepth > 0) {
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth - 1);
                    }

                    $neighbors[] = ($reel - 1) * 6 + $landingDepth;
                    if ($landingDepth < 5) {
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth + 1);
                    }

                }
                if ($reel < 4) {
                    if ($landingDepth > 0) {
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth - 1);
                    }

                    $neighbors[] = ($reel + 1) * 6 + $landingDepth;
                    if ($landingDepth < 5) {
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth + 1);
                    }

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

    private function tnt(string $blocks, string $board, array $pickaxePhase, array &$hpState = []): array
    {
        if (strlen($blocks) !== 30 || strlen($board) !== 15) {
            return [];
        }

        $blockRows = str_split($blocks, 6);
        $boardRows = str_split($board, 3);

        // Используем hpState напрямую (он уже содержит HP после mine())
        // Для блоков, которых нет в hpState, инициализируем из blockHP
        $blockHPRemaining = [];
        for ($i = 0; $i < 30; $i++) {
            $blockType = $blocks[$i] ?? null;

            // Блоки L уже сломаны
            if ($blockType === 'L') {
                $blockHPRemaining[$i] = 0;
                continue;
            }

            // Используем сохраненное состояние HP из hpState
            if (isset($hpState[$i])) {
                $blockHPRemaining[$i] = $hpState[$i];
            } elseif ($blockType !== null && isset($this->blockHP[$blockType])) {
                $blockHPRemaining[$i] = $this->blockHP[$blockType];
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
                if ($landingDepth > 0) {
                    $neighbors[] = $reel * 6 + ($landingDepth - 1);
                }

                if ($landingDepth < 5) {
                    $neighbors[] = $reel * 6 + ($landingDepth + 1);
                }

                // соседние столбцы (диагонали + вертикаль) от landingDepth
                if ($reel > 0) {
                    if ($landingDepth > 0) {
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth - 1);
                    }

                    $neighbors[] = ($reel - 1) * 6 + $landingDepth;
                    if ($landingDepth < 5) {
                        $neighbors[] = ($reel - 1) * 6 + ($landingDepth + 1);
                    }

                }
                if ($reel < 4) {
                    if ($landingDepth > 0) {
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth - 1);
                    }

                    $neighbors[] = ($reel + 1) * 6 + $landingDepth;
                    if ($landingDepth < 5) {
                        $neighbors[] = ($reel + 1) * 6 + ($landingDepth + 1);
                    }

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
                                'd' => (int) (80 * (1 - $boostFactor * 0.3)),
                                'c' => (int) (20 * (1 + $boostFactor)),
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
                                'd' => (int) (20 * (1 - $boostFactor)),
                                'c' => (int) (50 * (1 - $boostFactor * 0.2)),
                                'r' => (int) (30 * (1 + $boostFactor * 1.5)),
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
                                'c' => (int) (60 * (1 - $boostFactor * 0.3)),
                                'r' => (int) (40 * (1 + $boostFactor * 1.2)),
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
                                'r' => (int) (40 * (1 - $boostFactor * 0.5)),
                                'g' => (int) (40 * (1 + $boostFactor * 0.8)),
                                'm' => (int) (20 * (1 + $boostFactor * 2)),
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
                                'm' => (int) (60 * (1 - $boostFactor * 0.3)),
                                'o' => (int) (40 * (1 + $boostFactor * 1.5)),
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

}
