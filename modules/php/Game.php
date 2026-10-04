<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\GameOn;
use Bga\Games\makaucloudnein\Cards\CardManager;

class Game extends \Bga\GameFramework\Table
{
    public array $cardTypes;
    public array $st;

    public function __construct()
    {
        parent::__construct();

        $this->cardManager = new CardManager($this);
        $this->cards = $this->cardManager->cards;

        $this->cardTypes = [
            "suits" => [
                1 => [
                    'name' => clienttranslate('Spade')
                ],
                2 => [
                    'name' => clienttranslate('Heart')
                ],
                3 => [
                    'name' => clienttranslate('Club')
                ],
                4 => [
                    'name' => clienttranslate('Diamond')
                ]
            ],
            "ranks" => [
                2 => ['name' => 'Two'],
                3 => ['name' => 'Three'],
                4 => ['name' => 'Four'],
                5 => ['name' => 'Five'],
                6 => ['name' => 'Six'],
                7 => ['name' => 'Seven'],
                8 => ['name' => 'Eight'],
                9 => ['name' => 'Nine'],
                10 => ['name' => 'Ten'],
                11 => ['name' => clienttranslate('Jack')],
                12 => ['name' => clienttranslate('Queen')],
                13 => ['name' => clienttranslate('King')],
                14 => ['name' => clienttranslate('Ace')],
                15 => ['name' => clienttranslate('Joker')]
            ]
        ];
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $st = [];

        if ($this->bga->globals->has('state'))
            $st = json_decode($this->bga->globals->get('state'), true);
        else $st = $this->reset();

        $st['playerId'] = $currentPlayerId;
        $st['hand'] = $this->cards->getItemsInLocation(['hand', $currentPlayerId])->values();
        $st['discard'] = $this->cards->getItemsInLocation('discard')->values();
        $st['deck'] = $this->cards->countItemsInLocation('deck');

        return $st;
    }

    public function reset(): array
    {
        $globals = $this->bga->globals;
        $cards = $this->cards;

        $currentPlayerId = $this->getPlayerIdByNo(1);
        $playerIds = array_keys($this->loadPlayersBasicInfos());

        $this->cardManager->deal();

        $st = [
            'playerIds' => $playerIds,
            'currentPlayerId' => $currentPlayerId,
            'draw' => 0,
            'skip' => 0,
            'rankPick' => 0,
            'suitPick' => 0,
            'rankDemand' => 0,
            'suitDemand' => 0,
            'lastJack' => 0,
            'battleKing' => 0,
            'drew' => 0,
            'reverse' => 0,
        ];

        for ($i = 0; $i < count($playerIds); $i++) {
            $playerId = $playerIds[$i];
            $st["skip{$playerId}"] = 0;
            $st["makau{$playerId}"] = 0;
            $st["hand{$playerId}"] = $this->cards->countItemsInLocation(['hand', $playerId]);
        }

        $globals->set('state', json_encode($st));

        return $st;
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->cardManager->initDb();
        $this->cardManager->setup();
        $this->cards = $this->cardManager->cards;

        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];

        foreach ($players as $playerId => $player) {
            // Now you can access both $playerId and $player array
            $queryValues[] = vsprintf("(%s, '%s', '%s')", [
                $playerId,
                array_shift($defaultColors),
                addslashes($player["player_name"])
            ]);
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $queryValues)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        $this->reset();

        return GameOn::class;
    }

    public function err($currentPlayerId, $message)
    {
        $this->notify->player(
            $currentPlayerId,
            'InvalidPlay',
            $message,
            ['message' => $message]
        );
        return null;
    }
}
