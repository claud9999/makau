<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\GameStart;
use Bga\Games\makaucloudnein\Cards\CardManager;

class Game extends \Bga\GameFramework\Table
{
    public array $cardTypes;
    public array $st;

    const NO_DEMAND = 0;
    const DRAW_DEMAND = 1;
    const SKIP_DEMAND = 2;
    const RANK_DEMAND = 3;
    const SUIT_DEMAND = 4;

    const SPADE = 1;
    const HEART = 2;
    const CLUB = 3;
    const DIAMOND = 4;
    const ANY_SUIT = 20;

    const ACE = 1;
    const JACK = 11;
    const QUEEN = 12;
    const KING = 13;
    const JOKER = 14;
    const ANY_RANK = 20;

    public function __construct()
    {
        parent::__construct();

        $this->cardManager = new CardManager($this);
        $this->cards = $this->cardManager->cards;

        $this->cardTypes = [
            "suit" => [
                self::SPADE => [
                    'name' => clienttranslate('Spade')
                ],
                self::HEART => [
                    'name' => clienttranslate('Heart')
                ],
                self::CLUB => [
                    'name' => clienttranslate('Club')
                ],
                self::DIAMOND => [
                    'name' => clienttranslate('Diamond')
                ]
            ],
            "rank" => [
                self::ACE => ['name' => clienttranslate('Ace')],
                2 => ['name' => clienttranslate('Two')],
                3 => ['name' => clienttranslate('Three')],
                4 => ['name' => clienttranslate('Four')],
                5 => ['name' => clienttranslate('Five')],
                6 => ['name' => clienttranslate('Six')],
                7 => ['name' => clienttranslate('Seven')],
                8 => ['name' => clienttranslate('Eight')],
                9 => ['name' => clienttranslate('Nine')],
                10 => ['name' => clienttranslate('Ten')],
                self::JACK => ['name' => clienttranslate('Jack')],
                self::QUEEN => ['name' => clienttranslate('Queen')],
                self::KING => ['name' => clienttranslate('King')],
                self::JOKER => ['name' => clienttranslate('Joker')]
            ]
        ];
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $globals = $this->bga->globals;
        $cards = $this->cards;

        if ($globals->has('state'))
            $st = json_decode($globals->get('state'), true);
        else $st = $this->reset();

        $st['playerId'] = $currentPlayerId;
        $st['hand'] = $cards->getItemsInLocation(['hand', $currentPlayerId])->values();
        $st['discard'] = $cards->getItemsInLocation('discard')->values();
        $st['deck'] = $cards->countItemsInLocation('deck');
        for ($i = 0; $i < count($st['playerIds']); $i++) {
            $playerId = $st['playerIds'][$i];
            $st["hand{$playerId}"] = $cards->countItemsInLocation(['hand', $playerId]);
        }

        return $st;
    }

    public function reset(): array
    {
        $globals = $this->bga->globals;
        $cards = $this->cards;

        $currentPlayerId = $this->getPlayerIdByNo(1);
        $playerIds = array_keys($this->loadPlayersBasicInfos());

        // reset jokers
        foreach ($cards->getAllItems()->values() as $card) {
            if ($card->joker == 1) {
                $card->rank = self::JOKER;
                $card->suit = 0;
                $cards->updateItem($card, ['rank', 'suit']);
            }
        }

        $this->cardManager->deal();

        $st = [
            'playerIds' => $playerIds,
            'currentPlayerId' => $currentPlayerId,
        ];

        $st['demand'] = Game::NO_DEMAND;
        $st['demandArg'] = 0;
        $st['rankPick'] = 0;
        $st['suitPick'] = 0;
        $st['lastJack'] = 0;
        $st['battleKing'] = 0;
        $st['drew'] = 0;
        $st['reverse'] = 0;

        for ($i = 0; $i < count($st['playerIds']); $i++) {
            $playerId = $st['playerIds'][$i];
            $st["skip{$playerId}"] = 0;
            $st["makau{$playerId}"] = 0;
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

        $this->activeNextPlayer();

        return GameStart::class;
    }

    public function getGameProgression()
    {
        $highest = 0;
        $st = json_decode($this->bga->globals->get('state'), true);
        for ($i = 0; $i < count($st['playerIds']); $i++) {
            $playerId = $st['playerIds'][$i];
            $playerScore = $this->bga->playerScore;
            if ($playerScore->get($playerId) > $highest)
                $highest = $playerScore->get($playerId) > $highest;
        }

        return $highest * 10;
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
