<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\NewHand;
use Bga\GameFramework\Components\Counters\PlayerCounter;
use Bga\Games\makaucloudnein\Cards\CardManager;

class Game extends \Bga\GameFramework\Table
{
    public array $card_types;
    public int $skipcount;
    public int $drawcount;

    public function __construct()
    {
        parent::__construct();

        $this->cardManager = new CardManager($this);
        $this->cards = $this->cardManager->cards;

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
            "ranks" => [
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
        $cards = $this->cards;
        $result = [];
        // WARNING: We must only return information visible by the current player (using $currentPlayerId).

        // Get information about players.
        // NOTE: you can retrieve some extra field you added for "player" table in `dbmodel.sql` if you need it.
        $result["players"] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score` FROM `player`"
        );

        $result['deck'] = $cards->countItemsInLocation('deck');
        $result['hand'] = $cards->getItemsInLocation(['hand', $currentPlayerId]);
        $result['discard'] = $cards->getItemsInLocation('discard');
        $result['skipcount'] = $this->skipcount;
        $result['drawcount'] = $this->drawcount;

        return $result;
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->cardManager->initDb();
        $this->cardManager->setup();
        $this->cards = $this->cardManager->cards;

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


        $this->skipcount = 0;
        $this->drawcount = 0;

        $this->activeNextPlayer();

        return NewHand::class;
    }

    function getCardName($card): string
    {
        return $this->card_types['suits'][$card->suit]['name'] . ' of ' . $this->card_types['ranks'][$card->rank]['name'];
    }

    function getCardNames($cards): array
    {
        $names = [];
        foreach ($cards as $card) {
            $names[] = $this->getCardName($card);
        }
        return $names;
    }

    function getPlayableCards($player_id): array
    {
        $hand = $this->cardManager->getPlayerHand($player_id);
        $playable_card_ids = [];
        $all_ids = array_keys($hand);

        foreach ($hand as $card) if ($card->rank != 2) $playable_card_ids[] = $card->id;
        return $playable_card_ids;
    }

    public function isSpecial($card)
    {
        return $card->rank == 12 // queen
            || $card->rank == 2 // draw 2
            || $card->rank == 3 // draw 3
        ;
    }
}
