<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\NewHand;
use Bga\GameFramework\Components\Counters\PlayerCounter;
use Bga\GameFramework\Components\Decks\DeckFactory;

class Game extends \Bga\GameFramework\Table
{
    public array $card_types;
    public int $skipcount;
    public int $drawcount;

    public function __construct()
    {
        parent::__construct();

        $this->cards = $this->deckFactory->createDeck('card'); // 'card' is the name of the database

        $this->card_types = [
            "suits" => [
                1 => [
                    'name' => clienttranslate('Spade'),
                ],
                2 => [
                    'name' => clienttranslate('Heart'),
                ],
                3 => [
                    'name' => clienttranslate('Club'),
                ],
                4 => [
                    'name' => clienttranslate('Diamond'),
                ]
            ],
            "types" => [
                2 => ['name' => '2'],
                3 => ['name' => '3'],
                4 => ['name' => '4'],
                5 => ['name' => '5'],
                6 => ['name' => '6'],
                7 => ['name' => '7'],
                8 => ['name' => '8'],
                9 => ['name' => '9'],
                10 => ['name' => '10'],
                11 => ['name' => clienttranslate('J')],
                12 => ['name' => clienttranslate('Q')],
                13 => ['name' => clienttranslate('K')],
                14 => ['name' => clienttranslate('A')]
            ]
        ];
        $this->skipcount = 0;
        $this->drawcount = 0;
    }

    public function getGameProgression()
    {
        return 0;
    }

    public function upgradeTableDb($from_version) {}

    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];
        // WARNING: We must only return information visible by the current player (using $currentPlayerId).

        // Get information about players.
        // NOTE: you can retrieve some extra field you added for "player" table in `dbmodel.sql` if you need it.
        $result["players"] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score` FROM `player`"
        );

        $result['deck'] = $this->cards->countCardsInLocation('deck');
        $result['hand'] = $this->cards->getCardsInLocation('hand', $currentPlayerId);
        $result['discard'] = $this->cards->getCardsInLocation('discard');
        $result['skipcount'] = $this->skipcount;
        $result['drawcount'] = $this->drawcount;

        return $result;
    }

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"]),
            ]);
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // Init global values with their initial values.

        $cards = [];
        foreach ($this->card_types["suits"] as $suit => $suit_info) {
            // spade, heart, diamond, club
            foreach ($this->card_types["types"] as $value => $info_value) {
                //  2, 3, 4, ... K, A
                $cards[] = ['type' => $suit, 'type_arg' => $value, 'nbr' => 1];
            }
        }
        
        $this->cards->createCards($cards, 'deck');
        $this->skipcount = 0;
        $this->drawcount = 0;

        $this->activeNextPlayer();

        return NewHand::class;
    }

    /**
     * Example of debug function.
     * Here, jump to a state you want to test (by default, jump to next player state)
     * You can trigger it on Studio using the Debug button on the right of the top bar.
     */
    public function debug_goToState(int $state = 3)
    {
        $this->gamestate->jumpToState($state);
    }

    /**
     * Another example of debug function, to easily test the zombie code.
     */
    public function debug_playOneMove()
    {
        $this->bga->debug->playUntil(fn(int $count) => $count == 1);
    }

    /*
    Another example of debug function, to easily create situations you want to test.
    Here, put a card you want to test in your hand (assuming you use the Deck component).

    public function debug_setCardInHand(int $cardType, int $playerId) {
        $card = array_values($this->cards->getCardsOfType($cardType))[0];
        $this->cards->moveCard($card['id'], 'hand', $playerId);
    }
    */

    function getPlayableCards($player_id): array
    {
        $hand = $this->cards->getPlayerHand($player_id);
        $playable_card_ids = [];
        $all_ids = array_keys($hand);

        foreach ($hand as $card) if ($card['type'] != 2) $playable_card_ids[] = $card['id'];
        return $playable_card_ids;
    }
}
