# Minedrop Game Service - Пример использования

## Описание

`MinedropGameService` - это цельный сервис для генерации результатов игры Minedrop.

## Структура игры

### Верхняя сетка (5x3) - Барабаны с символами
- Кирки с разной силой: '3', '4', '5', '6', '7', '8'
- Специальные символы: 't' (TNT), 's' (special)

### Нижняя сетка (5x6) - Блоки шахты
- **30 блоков** расположены по 5 колонок и 6 рядов
- Блоки располагаются слоями сверху вниз

## Типы блоков

| Символ | Название | Прочность | Выплата |
|--------|----------|-----------|---------|
| d | Земля (dirt) | 1 удар | 0x |
| c | Камень (stone) | 2 удара | 0.1x |
| r | Рубин (ruby) | 4 удара | 1x |
| g | Золото (gold) | 5 ударов | 3x |
| m | Алмаз (diamond) | 6 ударов | 5x |
| o | Обсидиан (obsidian) | 7 ударов | 25x |

## Слои блоков (сверху вниз)

- **Ряды 0-1**: Земля (d)
- **Ряды 2-3**: Камень (c) с редкими рубинами (r)
- **Ряды 4-5**: Ценные блоки (r, g, m, o)

## Порядок индексации блоков

Блоки индексируются **по колонкам** (слева направо), внутри каждой колонки **сверху вниз**:

```
Колонка 0: индексы 0-5
Колонка 1: индексы 6-11
Колонка 2: индексы 12-17
Колонка 3: индексы 18-23
Колонка 4: индексы 24-29
```

Пример:
```
     Col0  Col1  Col2  Col3  Col4
Row0:  0     6    12    18    24
Row1:  1     7    13    19    25
Row2:  2     8    14    20    26
Row3:  3     9    15    21    27
Row4:  4    10    16    22    28
Row5:  5    11    17    23    29
```

## Использование в контроллере

```php
use App\Models\SessionGame;
use App\Service\Game\PlayService;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function play(Request $request, string $sessionUuid)
    {
        // Получение или создание сессии
        $session = SessionGame::firstOrCreate(
            ['session_uuid' => $sessionUuid],
            [
                'balance' => 1000000000, // 10,000$ в центах
                'total_bets' => 0,
                'total_wins' => 0,
                'is_active' => true,
                'youtube_mode' => false,
                'is_admin' => false,
            ]
        );

        // Создание сервиса
        $playService = new PlayService($session);

        // Генерация раунда
        $result = $playService->play($request);

        return response()->json($result);
    }

    public function getSession(string $sessionUuid)
    {
        $session = SessionGame::where('session_uuid', $sessionUuid)->firstOrFail();
        $playService = new PlayService($session);

        return response()->json($playService->getSessionInfo());
    }
}
```

## Пример запроса

### POST /api/game/play

**Request Body:**
```json
{
    "amount": 1000000,
    "mode": "BASE"
}
```

**Response:**
```json
{
    "balance": {
        "amount": 1058700000,
        "currency": "USD"
    },
    "round": {
        "betID": 2942618728,
        "amount": 1000000,
        "payout": 50000,
        "payoutMultiplier": 0.5,
        "active": false,
        "state": [
            {
                "index": 0,
                "s": 21132,
                "type": "s"
            },
            {
                "index": 1,
                "type": "reveal",
                "anticipation": 0,
                "blocks": "ddddddccccccrrrrrrggggggmmmmmmoo",
                "board": "x5xs5x5xx4xxxsx"
            },
            {
                "index": 2,
                "type": "mine",
                "pickaxePhase": [...],
                "tntPhase": []
            },
            {
                "index": 3,
                "type": "finalWin",
                "baseWinAmount": 50000,
                "multipliers": [],
                "finalWin": 50000
            }
        ],
        "mode": "BASE",
        "event": null
    }
}
```

## Логика выигрыша

1. **Базовый выигрыш**: Сумма всех выплат за разбитые блоки
2. **Множители**: Если вся колонка пробита - открывается сундук с множителем (2x-10x)
3. **Финальный выигрыш**: Базовый выигрыш + множители от сундуков

## Механика кирок

- Кирка с символом "5" имеет 5 ударов силы
- Кирка бьет сверху вниз по своей колонке
- Каждый удар наносит 1 урона блоку
- Если блок имеет прочность 2, нужно 2 удара чтобы его разбить

## Механика TNT

- TNT взрывает область 3x3 вокруг себя
- Полностью уничтожает все блоки в радиусе
- Дает выплату за каждый уничтоженный блок

## Открытие сундуков

- Сундук открывается если **все 6 блоков** в колонке разбиты
- Каждый сундук дает случайный множитель от 2x до 10x
- Можно открыть до 5 сундуков (по одному на колонку)

## Прямое использование MinedropGameService

```php
use App\Models\SessionGame;
use App\Service\Game\MinedropGameService;

// Получение сессии
$session = SessionGame::where('session_uuid', $sessionUuid)->firstOrFail();

// Создание сервиса
$gameService = new MinedropGameService($session);

// Генерация раунда
$result = $gameService->generateRound(
    betAmount: 1000000, // 10$ в центах
    mode: 'BASE'        // BASE или BONUS
);

// $result содержит полный результат раунда
```

## Режимы игры

- **BASE** - обычный режим
- **BONUS** - бонусный режим (бесплатные вращения)

## Важные замечания

1. Баланс автоматически обновляется при каждом раунде
2. Все ставки и выигрыши сохраняются в таблице `bets`
3. Используется mt_rand для генерации случайных чисел
4. Seed сохраняется для возможности воспроизведения результатов
5. Валюта всегда USD, суммы в центах (1000000 = 10$)

