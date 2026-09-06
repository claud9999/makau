<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein;

use Bga\Games\makaucloudnein\States\NewHand;
use Bga\GameFramework\Components\Counters\PlayerCounter;
use Bga\Games\makaucloudnein\Cards\CardManager;

class Game extends \Bga\GameFramework\Table
{
    public array $card_types;

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
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $cards = $this->cards;
        $result = [];

        $result["players"] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id` FROM `player`"
        );
        $result['deck'] = $cards->countItemsInLocation('deck');
        $result['hand'] = $cards->getItemsInLocation(['hand', $currentPlayerId]);
        $result['discard'] = $cards->getItemsInLocation('discard');
        $result['skip_count'] = $this->bga->globals->get('skip_count');
        $result['draw_count'] = $this->bga->globals->get('draw_count');
        $result['active_player_no'] = $this->bga->globals->get('active_player_no');
        $result['drew'] = $this->bga->globals->get('drew');


        return $result;
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->cardManager->initDb();
        $this->cardManager->setup();
        $this->cards = $this->cardManager->cards;

        $this->bga->globals->set('active_player_no', 1);
        $this->bga->globals->set('skip_count', 0);
        $this->bga->globals->set('draw_count', 0);
        $this->bga->globals->set('drew', 0);

        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        foreach ($players as $player_id => $player) {
            // Now you can access both $player_id and $player array
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"])
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

        return NewHand::class;
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
