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
    ];
    public function __construct(SessionGame $session, $bet)
    {
        $this->session = $session;
        $this->bet = $bet;
    }
    public function play(): array
    {
        $round = $this->getRound();

        return $round;
    }

    private function getRound(): array
    {
        $seed = $this->generateSeed(); // ОДИН seed на весь раунд

        $board = $this->generateBoard();
        $blocks = $this->generateBlocks();
        $blocks = "ddcrroddcrgmddcrgmddcrrgddcrrm";
        $board = "4x23xqxsx5x51x5";
        // $board = "xxxxxxxxxxxx12x";

        $pickaxePhase = $this->mine($blocks, $board);
        $tntPhase = $this->tnt($blocks, $board, $pickaxePhase);
        $totalWin = $this->calculateWin($pickaxePhase, $tntPhase);


        $round = [
            'balance' => [
                'amount' => $this->session->balance * 100,
                'currency' => 'USD',
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
                        's' => $seed, // ❗ БЕЗ cast
                        'type' => 's',
                    ],
                    [
                        'index' => 1,
                        'anticipation' => 0,
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

    private function generateBoard(): string
    {
        // x – пусто
        // 2,4,5 – кирки
        // s – special (1 удар)

        $symbols = ['x', 'x', '1', '2', '4', '5'];

        $board = '';
        for ($i = 0; $i < 15; $i++) {
            $board .= $symbols[array_rand($symbols)];
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
        return (string) mt_rand(1, 999999);
    }
    private function generateBlocks(): string
    {
        $blocks = '';

        for ($reel = 0; $reel < 5; $reel++) {
            for ($depth = 0; $depth < 6; $depth++) {

                switch ($depth) {

                    case 0: // 1 ряд — всегда земля
                        $block = 'd';
                        break;

                    case 1: // 2 ряд — земля, редко камень
                        $block = $this->weightedRandom([
                            'd' => 80,
                            'c' => 20,
                        ]);
                        break;

                    case 2: // 3 ряд — иногда земля, чаще камень или руда
                        $block = $this->weightedRandom([
                            'd' => 20,
                            'c' => 50,
                            'r' => 30,
                        ]);
                        break;

                    case 3: // 4 ряд — камень или руда
                        $block = $this->weightedRandom([
                            'c' => 60,
                            'r' => 40,
                        ]);
                        break;

                    case 4: // 5 ряд — руда / золото / алмаз
                        $block = $this->weightedRandom([
                            'r' => 40,
                            'g' => 40,
                            'm' => 20,
                        ]);
                        break;

                    case 5: // 6 ряд — алмаз / обсидиан
                        $block = $this->weightedRandom([
                            'm' => 60,
                            'o' => 40,
                        ]);
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
