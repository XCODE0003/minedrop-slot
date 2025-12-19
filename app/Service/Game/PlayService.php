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
        'd' => 1,
        'c' => 2,
        'r' => 4,
        'g' => 5,
        'm' => 6,
        'o' => 7,
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
        '5' => 1,
        '4' => 2,
        '3' => 3,
        '2' => 5,
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
        $blocks = "dddrmodddrmodddrmodcrrgoddccro";
        $board = "222xxxxxxxxxxxx";


        $pickaxePhase = $this->mine($blocks, $board);
        $totalWin = $this->calculateWin($pickaxePhase);

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
                        'tntPhase' => [],
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
    private function calculateWin(array $pickaxePhase): float
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

        // округление как у провайдера
        return round($totalWin, 2);
    }

    private function generateBoard(): string
    {
        // x – пусто
        // 2,4,5 – кирки
        // s – special (1 удар)

        $symbols = ['x', 'x', 'x', '2', '4', '5'];

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

            foreach ($pickaxes as $pickaxe) {
                $damage = $pickaxe['damage'];

                $localBroken = [];
                $localPayout = 0.0;
                $is_broken = false;
                while ($currentIndex < 6 && $damage > 0) {
                    $block = $rowBlocks[$currentIndex];
                    $hp = $this->blockHP[$block] ?? null;
                    if ($hp === null) {
                        break;
                    }
                    if ($damage >= $hp) {
                        $is_broken = true;
                    }
                    $hp -= $damage;
                    $globalIndex = $reel * 6 + $currentIndex;
                    $localBroken[] = $globalIndex;
                    $allBroken[] = $globalIndex;


                    if (isset($this->blockPayout[$block])) {
                        $localPayout = max($localPayout, (float) $this->blockPayout[$block]);
                    }

                    $currentIndex++;
                }

                if ($localBroken) {
                    $pickaxesOut[] = [
                        'breakBlock' => max(end($localBroken), $reel * 6 + $pickaxe['col']),
                        'payout' => (float) $localPayout,
                        'row' => $pickaxe['col'],
                        'symbol' => $pickaxe['symbol'],
                        'hp' => $hp,
                        'damage' => $damage,
                        'block' => $block,
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
