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
        $cards = $this->cards;
        $result = [];

        $result["player_ids"] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id` FROM `player`"
        );
        for ($i = 0; $i < count($result["player_ids"]); $i++) {
            if ($result["player_ids"][$i]["id"] == $currentPlayerId) {
                $result['my_player_no'] = $i;
            }
        }
        $result['deck'] = $cards->countItemsInLocation('deck');
        $result['hand'] = $cards->getItemsInLocation(['hand', $currentPlayerId]);
        $result['discards'] = $cards->getItemsInLocation('discard')->values();
        $result['skip_count'] = $this->bga->globals->get('skip_count');
        $result['draw_count'] = $this->bga->globals->get('draw_count');
        $result['suit_demand'] = $this->bga->globals->get('suit_demand');
        $result['rank_demand'] = $this->bga->globals->get('rank_demand');
        $result['last_jack'] = $this->bga->globals->get('last_jack');
        $result['active_player_no'] = $this->bga->globals->get('active_player_no');
        $result['drew'] = $this->bga->globals->get('drew');


        return $result;
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->cardManager->initDb();
        $this->cardManager->setup();
        $this->cards = $this->cardManager->cards;

        $this->bga->globals->set('active_player_no', 0);
        $this->bga->globals->set('skip_count', 0);
        $this->bga->globals->set('draw_count', 0);
        $this->bga->globals->set('drew', 0);
        $this->bga->globals->set('suit_demand', 0);
        $this->bga->globals->set('rank_demand', 0);
        $this->bga->globals->set('last_jack', 0);

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

    /* Logic:
    If there is a "draw demand", the only cards the current player can play is another draw card.
    If there is a "skip demand", the only cards the current player can play is another skip card.
    If the previous card was an A and a suit was called, only that suit can be played. If "Free" was called, any non-action card.
    If the a player played a J and a rank was called, only that rank can be played, or another J. If "Any" was called, any non-action card or another J.
    If no skip and no draw demanded, play matching suit or rank or a wild card (A, Q).

    Jokers, when played, are declared as to what kind of card they represent.

    Should match getPlayableCards in Game.js
    */
    function getPlayableCards($top, $cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            if ($this->bga->globals->get('skip_count') > 0) {
                if ($card->rank == 4)
                    $matchingCards[] = $card;
                $top = $card;
            } else if ($this->bga->globals->get('draw_count') > 0) {
                if (
                    $card->rank == 2
                    || $card->rank == 3
                    || $card->rank == 13 // king
                )
                    $matchingCards[] = $card;
                $top = $card;
            } else {
                if (
                    $card->rank == 12 // queen
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // queen
                )
                    $matchingCards[] = $card;
                $top = $card;
            }
        }

        return $matchingCards;
    }

    function getCardName($card): string {
        return ('The ' . $this->card_types['ranks'][$card->rank]['name'] . " of " . $this->card_types['suits'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }
}