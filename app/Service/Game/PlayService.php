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
        $round = $this->getRound();

        return $round;
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

        // === ВАРИАНТ 3: Несколько сундуков (2 сундука) ===
        // Комбинации: 2+2=4, 2+3=6, 2+5=10, 3+3=9, 2+2+2=8 и т.д.
        $multiChestCombos = [
            [2, 2],      // x4
            [2, 3],      // x6
            [3, 3],      // x9
            [2, 5],      // x10
            [2, 2, 2],   // x8
            [2, 2, 3],   // x12
        ];

        foreach ($multiChestCombos as $combo) {
            $totalMult = array_product($combo);
            $neededBase = $target / $totalMult;
            $numChests = count($combo);

            // Минимальный payout = сумма N самых дешёвых рядов
            $sortedPayouts = $reelPayouts;
            asort($sortedPayouts);
            $cheapestReels = array_slice(array_keys($sortedPayouts), 0, $numChests);
            $minBase = array_sum(array_slice(array_values($sortedPayouts), 0, $numChests));

            if ($neededBase >= $minBase && $neededBase <= $maxPayoutWithAllChests) {
                $candidates[] = [
                    'basePayout' => $neededBase,
                    'multiplier' => $totalMult,
                    'chestReel' => $cheapestReels[0],
                    'chestReels' => $cheapestReels,
                    'chestMultipliers' => $combo,
                    'error' => 0,
                    'complexity' => $neededBase
                ];
            } elseif ($neededBase < $minBase && $neededBase > 0) {
                $actualBase = $minBase;
                $error = abs($actualBase * $totalMult - $target);
                $candidates[] = [
                    'basePayout' => $actualBase,
                    'multiplier' => $totalMult,
                    'chestReel' => $cheapestReels[0],
                    'chestReels' => $cheapestReels,
                    'chestMultipliers' => $combo,
                    'error' => $error,
                    'complexity' => $actualBase
                ];
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

        // Фильтруем кандидатов с минимальной ошибкой
        $minError = min(array_column($candidates, 'error'));
        $bestCandidates = array_filter($candidates, fn($c) => abs($c['error'] - $minError) < 0.1);
        $bestCandidates = array_values($bestCandidates);

        // Находим минимальный basePayout среди лучших
        $minBase = min(array_column($bestCandidates, 'basePayout'));

        // Оставляем только кандидатов с оптимальным или близким basePayout (допуск 20%)
        $optimalCandidates = array_filter($bestCandidates, fn($c) => $c['basePayout'] <= $minBase * 1.2);
        $optimalCandidates = array_values($optimalCandidates);

        // Группируем по рядам для рандомизации
        $byReel = [];
        foreach ($optimalCandidates as $c) {
            $reel = $c['chestReel'];
            if ($reel !== null && !isset($byReel[$reel])) {
                $byReel[$reel] = $c;
            }
        }

        // Если нет рядов с сундуками, вернём оптимальный кандидат
        if (empty($byReel)) {
            shuffle($optimalCandidates);
            return $optimalCandidates[0];
        }

        // Выбираем случайный ряд из оптимальных
        $reelOptions = array_values($byReel);
        shuffle($reelOptions);

        return $reelOptions[0];
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

            ray("Round $round: currentPayout=$currentPayout, targetBase=$targetBasePayout, needed=$payoutNeededThisRound");

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

            // Если target уже достигнут - добавляем визуальную активность в РАЗНЫЕ ряды
            if ($currentPayout >= $targetBasePayout * 0.85) {
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
                }

                $boards[] = $board;
                ray("SIM Round $round (target reached): board=$board");
                continue; // Переходим к следующему раунду
            }

            // Для каждого ряда генерируем кирки
            foreach ($reelOrder as $reel) {

                // Проверяем достигли ли мы цели раунда
                if ($reelsProcessed >= $minReelsToProcess && $roundPayout >= $payoutNeededThisRound) {
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
                    continue; // Ряд сломан
                }

                // Проверяем запланирован ли сундук для этого ряда
                $reelHasPlannedChest = in_array($reel, $chestsToOpen);

                // Если мы уже у последнего блока (startDepth=5) - это сундук
                if ($startDepth == 5) {
                    // Открываем только если этот ряд запланирован
                    if (!$reelHasPlannedChest) {
                        ray("Пропускаем сундук ряда $reel (не запланирован)");
                        continue;
                    }
                }

                // Сколько слотов доступно для кирок (не занятых 's')
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

                // Сколько ещё нужно payout в этом раунде?
                $payoutStillNeeded = max(1, $payoutNeededThisRound - $roundPayout);

                // Если это первый ряд раунда - ОБЯЗАТЕЛЬНО добавляем кирки
                $forceMinDamage = ($reelsProcessed == 0);

                // Если target был достигнут - всё равно ломаем блоки (игра должна быть интересной)
                // Но ограничиваем до 1 блока за раунд
                $limitToOneBlock = $targetReachedAtStart;

                // Считаем сколько HP нужно сломать чтобы получить нужный payout
                $hpToBreak = 0;
                $expectedPayout = 0;
                $remainingToTarget = max(0, $targetBasePayout - $currentPayout);

                // Определяем максимальную глубину для этого ряда
                // Открываем сундук (depth=5) ТОЛЬКО если этот ряд в списке запланированных сундуков
                $canOpenChest = in_array($reel, $chestsToOpen);
                $maxDepth = $canOpenChest ? 6 : 5; // 6 = включая depth=5, 5 = до depth=4

                for ($d = $startDepth; $d < $maxDepth; $d++) {
                    $idx = $reel * 6 + $d;
                    $blockPayout = $this->blockPayout[$blocksArray[$idx]] ?? 0;

                    // Текущий projected total ПЕРЕД добавлением этого блока
                    $currentProjected = $currentPayout + $roundPayout + $expectedPayout;
                    // Projected total ПОСЛЕ добавления этого блока
                    $projectedAfter = $currentProjected + $blockPayout;

                    // Если это сундучный ряд - ломаем до конца несмотря ни на что
                    if ($canOpenChest) {
                        $hpToBreak += $hpState[$idx];
                        $expectedPayout += $blockPayout;
                        continue;
                    }

                    // Если уже достигли target (даже без этого блока) - не ломаем больше
                    // Допускаем небольшой недобор (95%) чтобы достичь target
                    // Прерываем если это НЕ первый блок этого ряда ИЛИ уже обработали ряды в этом раунде
                    $alreadyHasProgress = ($hpToBreak > 0 || $reelsProcessed > 0);
                    if ($currentProjected >= $targetBasePayout * 0.95 && $alreadyHasProgress) {
                        break;
                    }

                    // Если этот блок сильно превысит target - не ломаем
                    if ($projectedAfter > $targetBasePayout * 1.05 && $hpToBreak > 0) {
                        break;
                    }

                    // Если достигли цели раунда - достаточно
                    if ($expectedPayout >= $payoutStillNeeded) {
                        break;
                    }

                    $hpToBreak += $hpState[$idx];
                    $expectedPayout += $blockPayout;

                    // Если target уже достигнут на начало раунда, ломаем только 1 блок
                    if ($targetReachedAtStart) {
                        break;
                    }
                }

                // Если форсируем минимум - добавляем хотя бы 1 блок
                if ($forceMinDamage && $hpToBreak == 0) {
                    $idx = $reel * 6 + $startDepth;
                    $hpToBreak = $hpState[$idx];
                }

                // Максимальный урон = количество слотов * 5 (алмазная кирка)
                $maxDamageThisReel = count($availablePositions) * 5;

                // Сколько урона нанести (ограничено слотами и необходимостью)
                $damageThisRound = min($hpToBreak, $maxDamageThisReel);

                // Если target уже достигнут - ломаем только 1 блок для прогресса
                if ($limitToOneBlock) {
                    // Находим HP первого несломанного блока
                    $firstBlockHP = $hpState[$reel * 6 + $startDepth] ?? 1;
                    $damageThisRound = $firstBlockHP; // Ровно на 1 блок
                }

                // Минимум 1 урон если форсируем
                if ($forceMinDamage && $damageThisRound == 0) {
                    $damageThisRound = 1;
                }

                // Подбираем кирки (ограничено количеством слотов)
                $pickaxes = $this->selectPickaxesForDamage($damageThisRound);
                while (count($pickaxes) > count($availablePositions)) {
                    array_pop($pickaxes);
                }

                shuffle($availablePositions);

                foreach ($pickaxes as $i => $pickaxe) {
                    if (isset($availablePositions[$i])) {
                        $board[$boardStart + $availablePositions[$i]] = $pickaxe;
                    }
                }

                // Симулируем урон для обновления hpState
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

            $boards[] = $board;
            ray("SIM Round $round: board=$board, basePayout=$currentPayout / $targetBasePayout");
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
